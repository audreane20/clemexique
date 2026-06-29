<?php

namespace App\Controller;

use App\Helper\Translator;
use App\Model\PropertyModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

class PropertyController
{
    private PropertyModel $propertyModel;
    private Twig $twig;
    private string $basePath;
    private Translator $translator;

    private const MAX_SKIPPED_NAMES_IN_MESSAGE = 12;
    private const IMAGE_OPTIMIZE_THRESHOLD_BYTES = 8000000;
    private const PNG_OPTIMIZE_THRESHOLD_BYTES = 4000000;
    private const UPLOAD_PROGRESS_TTL = 1800;
    private const DIRECT_UPLOAD_URL_TTL = 7200;
    private const DIRECT_UPLOAD_MULTIPART_THRESHOLD_BYTES = 20971520;
    private const DIRECT_UPLOAD_MULTIPART_PART_SIZE = 10485760;

    public function __construct(PropertyModel $propertyModel, Twig $twig, string $basePath, Translator $translator)
    {
        $this->propertyModel = $propertyModel;
        $this->twig = $twig;
        $this->basePath = $basePath;
        $this->translator = $translator;
    }

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $editingProperty = null;

        if (!empty($queryParams['edit'])) {
            $editingProperty = $this->propertyModel->findById((int) $queryParams['edit']);
        }

        return $this->renderAdminProperties($response, $editingProperty);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $property = $this->propertyModel->findById($id);

        if ($property === null) {
            $response = $response->withStatus(404);

            return $this->twig->render($response, 'property-detail.html.twig', [
                'page_title_key' => 'properties.not_found_title',
                'property' => null,
            ]);
        }

        return $this->twig->render($response, 'property-detail.html.twig', [
            'page_title' => $property['name'],
            'property' => $property,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $rawData = (array) $request->getParsedBody();
        $directUploadManifest = $this->extractDirectUploadManifest($rawData);
        $data = $this->normalizePropertyFormData($rawData);
        $queryParams = $request->getQueryParams();
        $progressToken = $this->sanitizeUploadProgressToken((string) ($queryParams['upload_progress_token'] ?? ($data['upload_progress_token'] ?? '')));
        unset($data['upload_progress_token']);

        if ($progressToken !== null) {
            $this->initializeUploadProgress($progressToken);
        }

        if ($this->shouldQueueDirectUploadImport($directUploadManifest)) {
            $jobId = $this->queuePropertyImportJob('create', $data, $directUploadManifest);
            $this->startBackgroundPropertyImportJob($jobId);
            $this->resumeSessionLock();

            return $response
                ->withHeader('Location', $this->basePath . '/admin/properties')
                ->withStatus(302);
        }

        if ($this->requestExceedsPostLimit($request)) {
            $this->failUploadProgress($progressToken, $this->translator->trans('properties.error_upload_too_large'));
            return $this->renderAdminProperties($response, null, $this->translator->trans('properties.error_upload_too_large'), $data);
        }

        $this->releaseSessionLock();

        try {
            $uploadedFiles = $request->getUploadedFiles();
            $uploadOutcome = $this->mergeUploadOutcomes(
                $this->storeUploadedImages($uploadedFiles['image_files'] ?? [], false, [], $progressToken),
                $this->storeDirectUploadedMedia($directUploadManifest, false, [], $progressToken)
            );
            $imageUrls = $uploadOutcome['stored'];
            $data['image_url'] = $this->firstImageUrl($imageUrls) ?? '';

            if ($data['image_url'] === '') {
                if ($uploadOutcome['submitted_count'] > 0) {
                    throw new \RuntimeException($this->buildUploadFailureMessage($uploadOutcome['skipped']));
                }

                throw new \RuntimeException($this->translator->trans('properties.error_upload_required'));
            }

            $propertyId = $this->propertyModel->create($data);
            $this->propertyModel->replaceImages($propertyId, $imageUrls);

            $this->completeUploadProgress($progressToken, $this->translator->trans('properties.upload_progress_complete_message'));
            $this->resumeSessionLock();
            $noticeGroups = $this->buildUploadSkippedGroups($uploadOutcome['skipped']);
            if ($noticeGroups !== null) {
                $_SESSION['property_admin_notice_groups'] = $noticeGroups;
            }
        } catch (\RuntimeException $exception) {
            $this->failUploadProgress($progressToken, $exception->getMessage());
            $this->resumeSessionLock();
            return $this->renderAdminProperties($response, null, $exception->getMessage(), $data);
        } catch (\InvalidArgumentException $exception) {
            $this->failUploadProgress($progressToken, $exception->getMessage());
            $this->resumeSessionLock();
            return $this->renderAdminProperties($response, null, $exception->getMessage(), $data);
        }

        return $response
            ->withHeader('Location', $this->basePath . '/admin/properties')
            ->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $rawData = (array) $request->getParsedBody();
        $directUploadManifest = $this->extractDirectUploadManifest($rawData);
        $data = $this->normalizePropertyFormData($rawData);
        $queryParams = $request->getQueryParams();
        $progressToken = $this->sanitizeUploadProgressToken((string) ($queryParams['upload_progress_token'] ?? ($data['upload_progress_token'] ?? '')));
        unset($data['upload_progress_token']);
        $property = $this->propertyModel->findById($id);

        if ($property === null) {
            throw new \InvalidArgumentException('Property not found.');
        }

        if ($progressToken !== null) {
            $this->initializeUploadProgress($progressToken);
        }

        if ($this->shouldQueueDirectUploadImport($directUploadManifest)) {
            $jobId = $this->queuePropertyImportJob('update', $data, $directUploadManifest, $id);
            $this->startBackgroundPropertyImportJob($jobId);
            $this->resumeSessionLock();

            return $response
                ->withHeader('Location', $this->basePath . '/admin/properties')
                ->withStatus(302);
        }

        if ($this->requestExceedsPostLimit($request)) {
            $this->failUploadProgress($progressToken, $this->translator->trans('properties.error_upload_too_large'));
            return $this->renderAdminProperties(
                $response,
                array_replace($property, $data),
                $this->translator->trans('properties.error_upload_too_large'),
                $data
            );
        }

        $this->releaseSessionLock();

        try {
            $uploadedFiles = $request->getUploadedFiles();
            $data['image_url'] = $property['image_url'];
            $uploadOutcome = $this->mergeUploadOutcomes(
                $this->storeUploadedImages($uploadedFiles['image_files'] ?? [], false, $property['images'] ?? [], $progressToken),
                $this->storeDirectUploadedMedia($directUploadManifest, false, $property['images'] ?? [], $progressToken)
            );
            $newImageUrls = $uploadOutcome['stored'];

            if ($newImageUrls === [] && $uploadOutcome['submitted_count'] > 0) {
                $message = $this->buildUploadFailureMessage($uploadOutcome['skipped']);
                $this->failUploadProgress($progressToken, $message);
                return $this->renderAdminProperties(
                    $response,
                    array_replace($property, $data),
                    $message,
                    $data
                );
            }

            if ($newImageUrls !== []) {
                $this->propertyModel->addImages($id, $newImageUrls);
            }

            $this->propertyModel->update($id, $data);

            $this->completeUploadProgress($progressToken, $this->translator->trans('properties.upload_progress_complete_message'));
            $this->resumeSessionLock();
            $noticeGroups = $this->buildUploadSkippedGroups($uploadOutcome['skipped']);
            if ($noticeGroups !== null) {
                $_SESSION['property_admin_notice_groups'] = $noticeGroups;
            }
        } catch (\RuntimeException $exception) {
            $this->failUploadProgress($progressToken, $exception->getMessage());
            $this->resumeSessionLock();
            return $this->renderAdminProperties($response, array_replace($property, $data), $exception->getMessage(), $data);
        } catch (\InvalidArgumentException $exception) {
            $this->failUploadProgress($progressToken, $exception->getMessage());
            $this->resumeSessionLock();
            return $this->renderAdminProperties($response, array_replace($property, $data), $exception->getMessage(), $data);
        }

        return $response
            ->withHeader('Location', $this->basePath . '/admin/properties')
            ->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        $this->propertyModel->delete($id);

        return $response
            ->withHeader('Location', $this->basePath . '/admin/properties')
            ->withStatus(302);
    }

    public function deleteImages(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $property = $this->propertyModel->findById($id);

        if ($property === null) {
            throw new \InvalidArgumentException('Property not found.');
        }

        $data = (array) $request->getParsedBody();
        $selectedImages = $data['image_urls'] ?? [];

        if (!is_array($selectedImages) || $selectedImages === []) {
            return $this->renderAdminProperties(
                $response,
                $property,
                $this->translator->trans('properties.error_delete_photos_required')
            );
        }

        try {
            $this->propertyModel->removeImages($id, $selectedImages);
        } catch (\InvalidArgumentException $exception) {
            $message = $exception->getMessage() === 'A property must keep at least one image.'
                ? $this->translator->trans('properties.error_delete_photos_last')
                : $this->translator->trans('properties.error_delete_photos_required');

            return $this->renderAdminProperties($response, $property, $message);
        }

        return $response
            ->withHeader('Location', $this->basePath . '/admin/properties')
            ->withStatus(302);
    }

    public function setPrimaryImage(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $property = $this->propertyModel->findById($id);

        if ($property === null) {
            throw new \InvalidArgumentException('Property not found.');
        }

        $data = (array) $request->getParsedBody();
        $imageUrl = trim((string) ($data['primary_image_url'] ?? ''));

        if ($imageUrl === '') {
            return $this->renderAdminProperties(
                $response,
                $property,
                $this->translator->trans('properties.error_primary_image_required')
            );
        }

        try {
            $this->propertyModel->setPrimaryImage($id, $imageUrl);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderAdminProperties(
                $response,
                $property,
                $this->translator->trans('properties.error_primary_image_invalid')
            );
        }

        return $response
            ->withHeader('Location', $this->basePath . '/admin/properties')
            ->withStatus(302);
    }

    public function uploadProgress(Request $request, Response $response, array $args): Response
    {
        $token = $this->sanitizeUploadProgressToken((string) ($args['token'] ?? ''));
        $payload = $token !== null ? $this->readUploadProgress($token) : null;

        if ($payload === null) {
            $payload = [
                'status' => 'unknown',
                'phase' => $this->translator->trans('properties.upload_progress_waiting_phase'),
                'message' => $this->translator->trans('properties.upload_progress_waiting_message'),
                'percent' => 0,
                'current' => 0,
                'total' => 0,
            ];
        }

        $response->getBody()->write((string) json_encode($payload));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function initUploadProgress(Request $request, Response $response): Response
    {
        $token = 'upload-' . bin2hex(random_bytes(16));
        $this->initializeUploadProgress($token);

        $response->getBody()->write((string) json_encode([
            'token' => $token,
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function importJobStatus(Request $request, Response $response, array $args): Response
    {
        $jobId = trim((string) ($args['jobId'] ?? ''));
        $job = $this->readPropertyImportJob($jobId);

        if ($job === null) {
            return $this->jsonResponse($response, [
                'error' => 'Import job not found.',
            ], 404);
        }

        $payload = [
            'job_id' => $job['job_id'] ?? $jobId,
            'status' => $job['status'] ?? 'queued',
            'message' => $job['message'] ?? '',
            'mode' => $job['mode'] ?? 'create',
        ];

        $progressToken = trim((string) ($job['progress_token'] ?? ''));
        $progress = $progressToken !== '' ? $this->readUploadProgress($progressToken) : null;

        if (is_array($progress)) {
            $payload['progress'] = $progress;
        }

        return $this->jsonResponse($response, $payload);
    }

    public function prepareDirectUpload(Request $request, Response $response): Response
    {
        if (!$this->isDirectUploadEnabled()) {
            return $this->jsonResponse($response, [
                'error' => $this->translator->trans('properties.error_direct_upload_not_configured'),
            ], 503);
        }

        $payload = json_decode((string) $request->getBody(), true);
        $files = is_array($payload['files'] ?? null) ? $payload['files'] : [];

        if ($files === []) {
            return $this->jsonResponse($response, [
                'error' => $this->translator->trans('properties.error_direct_upload_prepare_failed'),
            ], 400);
        }

        $targets = [];

        foreach ($files as $index => $file) {
            if (!is_array($file)) {
                return $this->jsonResponse($response, [
                    'error' => $this->translator->trans('properties.error_direct_upload_prepare_failed'),
                ], 400);
            }

            $name = trim((string) ($file['name'] ?? ''));
            $size = max(0, (int) ($file['size'] ?? 0));
            $contentType = $this->normalizeDirectUploadContentType(
                trim((string) ($file['type'] ?? '')),
                $name
            );

            if ($name === '' || $contentType === null) {
                return $this->jsonResponse($response, [
                    'error' => $this->translator->trans('properties.error_upload_invalid'),
                ], 400);
            }

            $key = $this->buildDirectUploadObjectKey($name, (int) $index);

            if ($this->shouldUseMultipartDirectUpload($size, $name)) {
                $uploadId = $this->createMultipartDirectUpload($key, $contentType);

                $targets[] = [
                    'key' => $key,
                    'name' => $name,
                    'type' => $contentType,
                    'strategy' => 'multipart',
                    'upload_id' => $uploadId,
                    'part_size' => self::DIRECT_UPLOAD_MULTIPART_PART_SIZE,
                    'parts' => $this->buildMultipartDirectUploadPartTargets($key, $uploadId, $size),
                ];
                continue;
            }

            $targets[] = [
                'key' => $key,
                'name' => $name,
                'type' => $contentType,
                'strategy' => 'single',
                'upload_url' => $this->createDirectUploadSignedUrl('PUT', $key, self::DIRECT_UPLOAD_URL_TTL, $contentType),
            ];
        }

        return $this->jsonResponse($response, [
            'targets' => $targets,
        ]);
    }

    public function completeDirectUploadMultipart(Request $request, Response $response): Response
    {
        if (!$this->isDirectUploadEnabled()) {
            return $this->jsonResponse($response, [
                'error' => $this->translator->trans('properties.error_direct_upload_not_configured'),
            ], 503);
        }

        $payload = json_decode((string) $request->getBody(), true);
        $key = trim((string) ($payload['key'] ?? ''));
        $uploadId = trim((string) ($payload['upload_id'] ?? ''));
        $expectedPartCount = max(0, (int) ($payload['part_count'] ?? 0));

        if (!$this->isDirectUploadObjectKeyAllowed($key) || $uploadId === '' || $expectedPartCount <= 0) {
            return $this->jsonResponse($response, [
                'error' => $this->translator->trans('properties.error_direct_upload_manifest_invalid'),
            ], 400);
        }

        try {
            $parts = $this->listMultipartDirectUploadParts($key, $uploadId);

            if (count($parts) !== $expectedPartCount) {
                throw new \RuntimeException($this->translator->trans('properties.direct_upload_failed'));
            }

            $this->completeMultipartDirectUpload($key, $uploadId, $parts);
        } catch (\RuntimeException $exception) {
            return $this->jsonResponse($response, [
                'error' => $exception->getMessage(),
            ], 502);
        }

        return $this->jsonResponse($response, [
            'ok' => true,
        ]);
    }

    public function abortDirectUploadMultipart(Request $request, Response $response): Response
    {
        if (!$this->isDirectUploadEnabled()) {
            return $this->jsonResponse($response, [
                'error' => $this->translator->trans('properties.error_direct_upload_not_configured'),
            ], 503);
        }

        $payload = json_decode((string) $request->getBody(), true);
        $key = trim((string) ($payload['key'] ?? ''));
        $uploadId = trim((string) ($payload['upload_id'] ?? ''));

        if (!$this->isDirectUploadObjectKeyAllowed($key) || $uploadId === '') {
            return $this->jsonResponse($response, [
                'error' => $this->translator->trans('properties.error_direct_upload_manifest_invalid'),
            ], 400);
        }

        $this->abortMultipartDirectUpload($key, $uploadId);

        return $this->jsonResponse($response, [
            'ok' => true,
        ]);
    }

    private function storeDirectUploadedMedia(array $manifest, bool $required = true, array $existingImageUrls = [], ?string $progressToken = null): array
    {
        if ($manifest === []) {
            return [
                'stored' => [],
                'skipped' => [],
                'submitted_count' => 0,
            ];
        }

        $storedImages = [];
        $skippedFiles = [];
        $submittedCount = 0;
        $knownHashes = $this->computeExistingImageHashes($existingImageUrls);
        $totalUnits = max(1, count($manifest));
        $currentUnit = 0;

        $this->updateUploadProgress(
            $progressToken,
            'processing',
            $this->translator->trans('properties.upload_progress_scanning_phase'),
            $this->translator->trans('properties.upload_progress_scanning_message'),
            0,
            $totalUnits
        );

        foreach ($manifest as $entry) {
            $submittedCount++;
            $displayName = $entry['name'];

            $this->updateUploadProgress(
                $progressToken,
                'processing',
                $this->translator->trans('properties.upload_progress_download_phase'),
                $this->translator->trans('properties.upload_progress_download_message') . ' ' . $displayName,
                $currentUnit,
                $totalUnits
            );

            $temporaryPath = null;

            try {
                $temporaryPath = $this->downloadDirectUploadedObjectToTemporaryPath($entry['key'], $displayName);

                if ($this->isZipFilePath($temporaryPath, $displayName)) {
                    $zipEntryCount = $this->countZipEntriesFromPath($temporaryPath);

                    if ($zipEntryCount > 1) {
                        $totalUnits += ($zipEntryCount - 1);
                    }

                    $zipOutcome = $this->storeZipMediaFromPath($temporaryPath, $knownHashes, $progressToken, $currentUnit, $totalUnits);

                    if (($zipOutcome['stored'] ?? []) === [] && ($zipOutcome['skipped'] ?? []) === []) {
                        $zipOutcome['skipped'] = [[
                            'name' => $displayName,
                            'reason' => 'unsupported',
                            'detail' => 'The ZIP upload was read, but no supported media files could be processed from it.',
                        ]];
                        $this->logPropertyConversionDebug(
                            'ZIP upload produced no stored or skipped media entries for "'
                            . $displayName
                            . '".'
                        );
                    }

                    $storedImages = array_merge($storedImages, $zipOutcome['stored']);
                    $skippedFiles = array_merge($skippedFiles, $zipOutcome['skipped']);
                    continue;
                }

                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_checking_phase'),
                    $this->translator->trans('properties.upload_progress_checking_message') . ' ' . $displayName,
                    $currentUnit,
                    $totalUnits
                );

                $storedUrl = $this->storeMediaFileFromPath($temporaryPath, $displayName, $progressToken, $currentUnit, $totalUnits, $displayName);
                $storedHash = $this->computeStoredMediaHash($storedUrl);

                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_duplicates_phase'),
                    $this->translator->trans('properties.upload_progress_duplicates_message') . ' ' . $displayName,
                    $currentUnit,
                    $totalUnits
                );

                if ($storedHash !== '' && isset($knownHashes[$storedHash])) {
                    $this->deleteManagedUploadFile($storedUrl);
                    $skippedFiles[] = ['name' => $displayName, 'reason' => 'duplicate'];
                    $currentUnit++;
                    continue;
                }

                $storedImages[] = $storedUrl;
                $currentUnit++;

                if ($storedHash !== '') {
                    $knownHashes[$storedHash] = true;
                }

                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_saving_phase'),
                    $this->translator->trans('properties.upload_progress_saving_message') . ' ' . $displayName,
                    $currentUnit,
                    $totalUnits
                );
            } catch (\RuntimeException $exception) {
                $this->logPropertyConversionDebug(
                    'Direct upload media skipped for "'
                    . $displayName
                    . '" with reason "'
                    . $this->mapUploadExceptionToReason($exception)
                    . '" and message: '
                    . $exception->getMessage()
                );
                $skippedFiles[] = [
                    'name' => $displayName,
                    'reason' => $this->mapUploadExceptionToReason($exception),
                    'detail' => $this->extractUploadExceptionDetail($exception),
                ];
                $currentUnit++;
            } catch (\Throwable $throwable) {
                $this->logPropertyConversionDebug(
                    'Unexpected direct upload processing failure for "'
                    . $displayName
                    . '": '
                    . $throwable::class
                    . ' - '
                    . $throwable->getMessage()
                );
                $skippedFiles[] = [
                    'name' => $displayName,
                    'reason' => 'unsupported',
                    'detail' => 'Unexpected processing failure: ' . $throwable->getMessage(),
                ];
                $currentUnit++;
            } finally {
                if (is_string($temporaryPath) && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }

                $this->deleteDirectUploadedObject($entry['key']);
            }
        }

        if ($required && $storedImages === []) {
            throw new \RuntimeException($this->buildUploadFailureMessage($skippedFiles));
        }

        return [
            'stored' => $storedImages,
            'skipped' => $this->uniqueSkippedFiles($skippedFiles),
            'submitted_count' => $submittedCount,
        ];
    }

    private function mergeUploadOutcomes(array ...$outcomes): array
    {
        $stored = [];
        $skipped = [];
        $submittedCount = 0;

        foreach ($outcomes as $outcome) {
            $stored = array_merge($stored, $outcome['stored'] ?? []);
            $skipped = array_merge($skipped, $outcome['skipped'] ?? []);
            $submittedCount += (int) ($outcome['submitted_count'] ?? 0);
        }

        return [
            'stored' => $stored,
            'skipped' => $this->uniqueSkippedFiles($skipped),
            'submitted_count' => $submittedCount,
        ];
    }

    private function storeUploadedImages(mixed $uploadedFiles, bool $required = true, array $existingImageUrls = [], ?string $progressToken = null): array
    {
        $files = [];

        if ($uploadedFiles instanceof UploadedFileInterface) {
            $files = [$uploadedFiles];
        } elseif (is_array($uploadedFiles)) {
            $files = $uploadedFiles;
        }

        $storedImages = [];
        $skippedFiles = [];
        $submittedCount = 0;
        $knownHashes = $this->computeExistingImageHashes($existingImageUrls);
        $totalUnits = max(1, $this->countUploadProcessingUnits($files));
        $currentUnit = 0;

        $this->updateUploadProgress(
            $progressToken,
            'processing',
            $this->translator->trans('properties.upload_progress_scanning_phase'),
            $this->translator->trans('properties.upload_progress_scanning_message'),
            0,
            $totalUnits
        );

        foreach ($files as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFileInterface) {
                continue;
            }

            if ($uploadedFile->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

             $submittedCount++;
            $displayName = $this->uploadedFileDisplayName($uploadedFile);

            if (in_array($uploadedFile->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $skippedFiles[] = ['name' => $displayName, 'reason' => 'too_large'];
                continue;
            }

            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                $skippedFiles[] = ['name' => $displayName, 'reason' => 'upload_error'];
                continue;
            }

            if ($this->isZipUpload($uploadedFile)) {
                $zipOutcome = $this->storeZipUploadedImages($uploadedFile, $knownHashes, $progressToken, $currentUnit, $totalUnits);
                $storedImages = array_merge($storedImages, $zipOutcome['stored']);
                $skippedFiles = array_merge($skippedFiles, $zipOutcome['skipped']);
                continue;
            }

            $this->updateUploadProgress(
                $progressToken,
                'processing',
                $this->translator->trans('properties.upload_progress_checking_phase'),
                $this->translator->trans('properties.upload_progress_checking_message') . ' ' . $displayName,
                $currentUnit,
                $totalUnits
            );

            try {
                $storedUrl = $this->storeSingleUploadedImage($uploadedFile, $progressToken, $currentUnit, $totalUnits, $displayName);
            } catch (\RuntimeException $exception) {
                $skippedFiles[] = [
                    'name' => $displayName,
                    'reason' => $this->mapUploadExceptionToReason($exception),
                    'detail' => $this->extractUploadExceptionDetail($exception),
                ];
                $currentUnit++;
                continue;
            }

            $this->updateUploadProgress(
                $progressToken,
                'processing',
                $this->translator->trans('properties.upload_progress_duplicates_phase'),
                $this->translator->trans('properties.upload_progress_duplicates_message') . ' ' . $displayName,
                $currentUnit,
                $totalUnits
            );

            $storedHash = $this->computeStoredMediaHash($storedUrl);

            if ($storedHash !== '' && isset($knownHashes[$storedHash])) {
                $this->deleteManagedUploadFile($storedUrl);
                $skippedFiles[] = ['name' => $displayName, 'reason' => 'duplicate'];
                $currentUnit++;
                continue;
            }

            $storedImages[] = $storedUrl;
            $currentUnit++;

            if ($storedHash !== '') {
                $knownHashes[$storedHash] = true;
            }

            $this->updateUploadProgress(
                $progressToken,
                'processing',
                $this->translator->trans('properties.upload_progress_saving_phase'),
                $this->translator->trans('properties.upload_progress_saving_message') . ' ' . $displayName,
                $currentUnit,
                $totalUnits
            );
        }

        if ($required && $storedImages === []) {
            throw new \RuntimeException($this->buildUploadFailureMessage($skippedFiles));
        }

        return [
            'stored' => $storedImages,
            'skipped' => $this->uniqueSkippedFiles($skippedFiles),
            'submitted_count' => $submittedCount,
        ];
    }

    private function isZipUpload(UploadedFileInterface $uploadedFile): bool
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $header = (string) $stream->read(4);
        $stream->rewind();

        return $header === "PK\x03\x04" || $header === "PK\x05\x06" || $header === "PK\x07\x08";
    }

    private function storeSingleUploadedImage(
        UploadedFileInterface $uploadedFile,
        ?string $progressToken = null,
        int $currentUnit = 0,
        int $totalUnits = 1,
        ?string $displayName = null
    ): string
    {
        $temporaryPath = $this->copyUploadedFileToTemporaryPath($uploadedFile);

        try {
            return $this->storeMediaFileFromPath(
                $temporaryPath,
                $this->uploadedFileDisplayName($uploadedFile),
                $progressToken,
                $currentUnit,
                $totalUnits,
                $displayName ?? $this->uploadedFileDisplayName($uploadedFile)
            );
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function storeZipUploadedImages(
        UploadedFileInterface $uploadedFile,
        array &$knownHashes,
        ?string $progressToken = null,
        int &$currentUnit = 0,
        int $totalUnits = 1
    ): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_zip_unavailable'));
        }

        $temporaryZipPath = $this->copyUploadedFileToTemporaryPath($uploadedFile);

        try {
            return $this->storeZipMediaFromPath($temporaryZipPath, $knownHashes, $progressToken, $currentUnit, $totalUnits);
        } finally {
            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
        }
    }

    private function storeZipMediaFromPath(
        string $temporaryZipPath,
        array &$knownHashes,
        ?string $progressToken = null,
        int &$currentUnit = 0,
        int $totalUnits = 1
    ): array {
        $zip = new \ZipArchive();
        $storedImages = [];
        $skippedFiles = [];

        if ($zip->open($temporaryZipPath) !== true) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = (string) $zip->getNameIndex($index);

                if ($entryName === '' || str_ends_with($entryName, '/')) {
                    continue;
                }

                $entryContents = $zip->getFromIndex($index);

                if (!is_string($entryContents) || $entryContents === '') {
                    $skippedFiles[] = [
                        'name' => $entryName,
                        'reason' => 'unsupported',
                        'detail' => 'The ZIP entry could not be read or was empty.',
                    ];
                    $this->logPropertyConversionDebug(
                        'ZIP entry could not be read for "'
                        . $entryName
                        . '" from archive '
                        . $temporaryZipPath
                    );
                    $currentUnit++;
                    continue;
                }

                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_extracting_phase'),
                    $this->translator->trans('properties.upload_progress_extracting_message') . ' ' . $entryName,
                    $currentUnit,
                    $totalUnits
                );

                $temporaryEntryPath = null;
                try {
                    $temporaryEntryPath = $this->writeTemporaryBinaryFile($entryContents, $entryName);
                    $storedUrl = $this->storeMediaFileFromPath($temporaryEntryPath, $entryName, $progressToken, $currentUnit, $totalUnits, $entryName);
                } catch (\RuntimeException $exception) {
                    $this->logPropertyConversionDebug(
                        'ZIP media entry skipped for "'
                        . $entryName
                        . '" with reason "'
                        . $this->mapUploadExceptionToReason($exception)
                        . '" and message: '
                        . $exception->getMessage()
                    );
                    $skippedFiles[] = [
                        'name' => $entryName,
                        'reason' => $this->mapUploadExceptionToReason($exception),
                        'detail' => $this->extractUploadExceptionDetail($exception),
                    ];
                    $currentUnit++;
                    continue;
                } catch (\Throwable $throwable) {
                    $this->logPropertyConversionDebug(
                        'Unexpected ZIP media processing failure for "'
                        . $entryName
                        . '": '
                        . $throwable::class
                        . ' - '
                        . $throwable->getMessage()
                    );
                    $skippedFiles[] = [
                        'name' => $entryName,
                        'reason' => 'unsupported',
                        'detail' => 'Unexpected processing failure: ' . $throwable->getMessage(),
                    ];
                    $currentUnit++;
                    continue;
                } finally {
                    if (is_string($temporaryEntryPath) && is_file($temporaryEntryPath)) {
                        @unlink($temporaryEntryPath);
                    }
                }

                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_duplicates_phase'),
                    $this->translator->trans('properties.upload_progress_duplicates_message') . ' ' . $entryName,
                    $currentUnit,
                    $totalUnits
                );

                $storedHash = $this->computeStoredMediaHash($storedUrl);

                if ($storedHash !== '' && isset($knownHashes[$storedHash])) {
                    $this->deleteManagedUploadFile($storedUrl);
                    $skippedFiles[] = ['name' => $entryName, 'reason' => 'duplicate'];
                    $currentUnit++;
                    continue;
                }

                $storedImages[] = $storedUrl;
                $currentUnit++;

                if ($storedHash !== '') {
                    $knownHashes[$storedHash] = true;
                }

                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_saving_phase'),
                    $this->translator->trans('properties.upload_progress_saving_message') . ' ' . $entryName,
                    $currentUnit,
                    $totalUnits
                );
            }
        } finally {
            $zip->close();
        }

        if ($this->shouldAttemptZipExtractFallback($storedImages, $skippedFiles)) {
            $this->logPropertyConversionDebug(
                'ZIP archive produced no stored media entries on the first pass. Attempting extract-to-directory fallback for '
                . $temporaryZipPath
            );

            $fallbackOutcome = $this->storeZipMediaFromExtractedDirectory(
                $temporaryZipPath,
                $knownHashes,
                $progressToken,
                $currentUnit,
                $totalUnits
            );

            if (($fallbackOutcome['stored'] ?? []) !== [] || ($fallbackOutcome['skipped'] ?? []) !== []) {
                return [
                    'stored' => $fallbackOutcome['stored'] ?? [],
                    'skipped' => $this->uniqueSkippedFiles(array_merge(
                        $fallbackOutcome['skipped'] ?? [],
                        $storedImages === [] ? [] : $skippedFiles
                    )),
                ];
            }

            $skippedFiles[] = [
                'name' => basename($temporaryZipPath),
                'reason' => 'unsupported',
                'detail' => 'The ZIP archive was opened, but no supported media files were found inside.',
            ];
            $this->logPropertyConversionDebug(
                'ZIP archive produced no stored or skipped media entries even after extract-to-directory fallback: '
                . $temporaryZipPath
            );
        }

        return [
            'stored' => $storedImages,
            'skipped' => $this->uniqueSkippedFiles($skippedFiles),
        ];
    }

    private function shouldAttemptZipExtractFallback(array $storedImages, array $skippedFiles): bool
    {
        if ($storedImages !== []) {
            return false;
        }

        if ($skippedFiles === []) {
            return true;
        }

        foreach ($skippedFiles as $skippedFile) {
            $reason = strtolower((string) ($skippedFile['reason'] ?? 'unsupported'));

            if ($reason !== 'unsupported') {
                return false;
            }
        }

        return true;
    }

    private function storeZipMediaFromExtractedDirectory(
        string $temporaryZipPath,
        array &$knownHashes,
        ?string $progressToken = null,
        int &$currentUnit = 0,
        int $totalUnits = 1
    ): array {
        $zip = new \ZipArchive();
        $storedImages = [];
        $skippedFiles = [];

        if ($zip->open($temporaryZipPath) !== true) {
            return [
                'stored' => [],
                'skipped' => [],
            ];
        }

        $extractDirectory = $this->createTemporaryDirectory('property_zip_extract_');

        try {
            if (!$zip->extractTo($extractDirectory)) {
                $this->logPropertyConversionDebug(
                    'ZIP extract-to-directory fallback failed for ' . $temporaryZipPath
                );

                return [
                    'stored' => [],
                    'skipped' => [],
                ];
            }
        } finally {
            $zip->close();
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractDirectory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }

                $sourcePath = $fileInfo->getPathname();
                $relativePath = str_replace('\\', '/', substr($sourcePath, strlen($extractDirectory) + 1));

                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_checking_phase'),
                    $this->translator->trans('properties.upload_progress_checking_message') . ' ' . $relativePath,
                    $currentUnit,
                    $totalUnits
                );

                try {
                    $storedUrl = $this->storeMediaFileFromPath(
                        $sourcePath,
                        $relativePath,
                        $progressToken,
                        $currentUnit,
                        $totalUnits,
                        $relativePath
                    );
                } catch (\RuntimeException $exception) {
                    $this->logPropertyConversionDebug(
                        'ZIP extract fallback skipped for "'
                        . $relativePath
                        . '" with reason "'
                        . $this->mapUploadExceptionToReason($exception)
                        . '" and message: '
                        . $exception->getMessage()
                    );
                    $skippedFiles[] = [
                        'name' => $relativePath,
                        'reason' => $this->mapUploadExceptionToReason($exception),
                        'detail' => $this->extractUploadExceptionDetail($exception),
                    ];
                    $currentUnit++;
                    continue;
                } catch (\Throwable $throwable) {
                    $this->logPropertyConversionDebug(
                        'Unexpected ZIP extract fallback failure for "'
                        . $relativePath
                        . '": '
                        . $throwable::class
                        . ' - '
                        . $throwable->getMessage()
                    );
                    $skippedFiles[] = [
                        'name' => $relativePath,
                        'reason' => 'unsupported',
                        'detail' => 'Unexpected processing failure: ' . $throwable->getMessage(),
                    ];
                    $currentUnit++;
                    continue;
                }

                $storedHash = $this->computeStoredMediaHash($storedUrl);

                if ($storedHash !== '' && isset($knownHashes[$storedHash])) {
                    $this->deleteManagedUploadFile($storedUrl);
                    $skippedFiles[] = ['name' => $relativePath, 'reason' => 'duplicate'];
                    $currentUnit++;
                    continue;
                }

                $storedImages[] = $storedUrl;
                $currentUnit++;

                if ($storedHash !== '') {
                    $knownHashes[$storedHash] = true;
                }
            }
        } finally {
            $this->deleteDirectoryRecursively($extractDirectory);
        }

        $this->logPropertyConversionDebug(
            'ZIP extract fallback completed for '
            . $temporaryZipPath
            . ' with '
            . count($storedImages)
            . ' stored file(s) and '
            . count($skippedFiles)
            . ' skipped file(s).'
        );

        return [
            'stored' => $storedImages,
            'skipped' => $this->uniqueSkippedFiles($skippedFiles),
        ];
    }

    private function createTemporaryDirectory(string $prefix): string
    {
        $directory = $this->managedTemporaryDirectory() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        return $directory;
    }

    private function deleteDirectoryRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    private function detectImageExtension(UploadedFileInterface $uploadedFile): ?string
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $header = (string) $stream->read(64);
        $stream->rewind();

        return $this->detectImageExtensionFromBinary($header);
    }

    private function detectMediaExtensionFromPath(string $sourcePath, string $originalName = ''): ?string
    {
        $header = @file_get_contents($sourcePath, false, null, 0, 64);

        if (is_string($header) && $header !== '') {
            $binaryDetected = $this->detectImageExtensionFromBinary($header);

            if ($binaryDetected !== null) {
                return $binaryDetected;
            }
        }

        if (function_exists('exif_imagetype')) {
            $imageType = @exif_imagetype($sourcePath);

            $mapped = match ($imageType) {
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                IMAGETYPE_WEBP => defined('IMAGETYPE_WEBP') ? 'webp' : null,
                IMAGETYPE_AVIF => defined('IMAGETYPE_AVIF') ? 'avif' : null,
                default => null,
            };

            if ($mapped !== null) {
                return $mapped;
            }
        }

        if (function_exists('getimagesize')) {
            $imageInfo = @getimagesize($sourcePath);

            if (is_array($imageInfo)) {
                $mime = strtolower((string) ($imageInfo['mime'] ?? ''));

                return match ($mime) {
                    'image/jpeg', 'image/pjpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'image/heic', 'image/heif' => 'heic',
                    'image/avif' => 'avif',
                    default => null,
                };
            }
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = strtolower((string) @finfo_file($finfo, $sourcePath));
                @finfo_close($finfo);

                if ($mime !== '') {
                    $mapped = match ($mime) {
                        'image/jpeg', 'image/pjpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        'image/heic', 'image/heif' => 'heic',
                        'image/avif' => 'avif',
                        'video/mp4' => 'mp4',
                        'video/quicktime' => 'mov',
                        'video/webm' => 'webm',
                        default => null,
                    };

                    if ($mapped !== null) {
                        return $mapped;
                    }
                }
            }
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif', 'mp4', 'mov', 'webm'], true) && @filesize($sourcePath) > 0) {
            if ($extension === 'heif') {
                return 'heic';
            }

            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return null;
    }

    private function detectImageExtensionFromBinary(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        if (str_starts_with($binary, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'png';
        }

        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        if (str_starts_with($binary, 'GIF87a') || str_starts_with($binary, 'GIF89a')) {
            return 'gif';
        }

        if (substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP') {
            return 'webp';
        }

        if (strlen($binary) >= 12 && substr($binary, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($binary, 8, 4));

            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'], true)) {
                return 'heic';
            }

            if (in_array($brand, ['avif', 'avis'], true)) {
                return 'avif';
            }

            if ($brand === 'qt  ') {
                return 'mov';
            }

            $videoBrands = ['isom', 'iso2', 'avc1', 'mp41', 'mp42', 'm4v ', '3gp4', '3gp5', '3gp6', 'msnv'];

            if (in_array($brand, array_map('strtolower', $videoBrands), true)) {
                return 'mp4';
            }
        }

        if (str_starts_with($binary, "\x1A\x45\xDF\xA3") && str_contains(strtolower(substr($binary, 0, 64)), 'webm')) {
            return 'webm';
        }

        return null;
    }

    private function firstImageUrl(array $mediaUrls): ?string
    {
        foreach ($mediaUrls as $mediaUrl) {
            $extension = strtolower(pathinfo(parse_url((string) $mediaUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                return (string) $mediaUrl;
            }
        }

        return null;
    }

    private function renderAdminProperties(
        Response $response,
        ?array $editingProperty = null,
        ?string $error = null,
        array $formData = []
    ): Response {
        $noticeGroups = $_SESSION['property_admin_notice_groups'] ?? null;
        unset($_SESSION['property_admin_notice_groups']);
        $activeImportJob = $this->consumeActivePropertyImportJobSummary();

        return $this->twig->render($response, 'admin/properties.html.twig', [
            'page_title_key' => 'properties.page_title',
            'properties' => $this->propertyModel->findAll(),
            'editing_property' => $editingProperty,
            'form_error' => $error,
            'form_notice_groups' => $noticeGroups,
            'form_data' => $formData,
            'form_error_groups' => $error !== null ? $this->buildUploadSkippedGroupsFromMessageContext($error) : null,
            'direct_upload_enabled' => $this->isDirectUploadEnabled(),
            'active_import_job' => $activeImportJob,
        ]);
    }

    public function processImportJobById(string $jobId): void
    {
        $job = $this->readPropertyImportJob($jobId);

        if ($job === null) {
            return;
        }

        $currentStatus = (string) ($job['status'] ?? 'queued');

        if (!in_array($currentStatus, ['queued', 'processing'], true)) {
            return;
        }

        $progressToken = trim((string) ($job['progress_token'] ?? ''));
        $formData = is_array($job['form_data'] ?? null) ? $job['form_data'] : [];
        $directUploadManifest = is_array($job['direct_upload_manifest'] ?? null) ? $job['direct_upload_manifest'] : [];
        $mode = (string) ($job['mode'] ?? 'create');
        $propertyId = (int) ($job['property_id'] ?? 0);

        $this->writePropertyImportJob($jobId, array_merge($job, [
            'status' => 'processing',
            'message' => 'Downloading and processing uploaded files.',
            'updated_at' => time(),
        ]));
        $this->initializeUploadProgress($progressToken);

        try {
            if ($mode === 'update') {
                $property = $this->propertyModel->findById($propertyId);

                if ($property === null) {
                    throw new \RuntimeException('Property not found.');
                }

                $data = $this->normalizePropertyFormData($formData);
                $data['image_url'] = $property['image_url'];

                $uploadOutcome = $this->storeDirectUploadedMedia($directUploadManifest, false, $property['images'] ?? [], $progressToken);
                $newImageUrls = $uploadOutcome['stored'];

                if ($newImageUrls === [] && $uploadOutcome['submitted_count'] > 0) {
                    throw new \RuntimeException($this->buildUploadFailureMessage($uploadOutcome['skipped']));
                }

                if ($newImageUrls !== []) {
                    $this->propertyModel->addImages($propertyId, $newImageUrls);
                }

                $this->propertyModel->update($propertyId, $data);
            } else {
                $data = $this->normalizePropertyFormData($formData);
                $uploadOutcome = $this->storeDirectUploadedMedia($directUploadManifest, false, [], $progressToken);
                $imageUrls = $uploadOutcome['stored'];
                $data['image_url'] = $this->firstImageUrl($imageUrls) ?? '';

                if ($data['image_url'] === '') {
                    if ($uploadOutcome['submitted_count'] > 0) {
                        throw new \RuntimeException($this->buildUploadFailureMessage($uploadOutcome['skipped']));
                    }

                    throw new \RuntimeException($this->translator->trans('properties.error_upload_required'));
                }

                $createdPropertyId = $this->propertyModel->create($data);
                $this->propertyModel->replaceImages($createdPropertyId, $imageUrls);
                $propertyId = $createdPropertyId;
            }

            $this->completeUploadProgress($progressToken, 'Import complete.');
            $this->writePropertyImportJob($jobId, array_merge($job, [
                'status' => 'complete',
                'message' => 'Import complete.',
                'property_id' => $propertyId,
                'updated_at' => time(),
            ]));
        } catch (\Throwable $exception) {
            $this->failUploadProgress($progressToken, $exception->getMessage());
            $this->writePropertyImportJob($jobId, array_merge($job, [
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'updated_at' => time(),
            ]));
            $this->logPropertyConversionDebug('Background property import failed for job "' . $jobId . '": ' . $exception->getMessage());
        }
    }

    private function uploadedFileDisplayName(UploadedFileInterface $uploadedFile): string
    {
        $filename = trim((string) $uploadedFile->getClientFilename());

        return $filename !== '' ? $filename : $this->translator->trans('properties.unknown_file');
    }

    private function buildUploadSkippedGroups(array $skippedFiles): ?array
    {
        $grouped = $this->groupSkippedFiles($skippedFiles);

        if ($grouped === []) {
            return null;
        }

        return [
            'title' => $this->translator->trans('properties.upload_skipped_prefix'),
            'groups' => $grouped,
        ];
    }

    private function buildUploadFailureMessage(array $skippedFiles): string
    {
        $grouped = $this->groupSkippedFiles($skippedFiles);

        if ($grouped === []) {
            return $this->translator->trans('properties.error_upload_required');
        }

        $lines = [$this->translator->trans('properties.error_upload_none_added_prefix')];

        foreach ($grouped as $group) {
            $lines[] = $group['label'] . ':';
            foreach ($group['names'] as $name) {
                $lines[] = '- ' . $name;
            }
            foreach ($group['details'] as $detail) {
                $lines[] = '! ' . preg_replace('/\s+/', ' ', trim($detail));
            }
        }

        return implode("\n", $lines);
    }

    private function groupSkippedFiles(array $skippedFiles): array
    {
        $groups = [];

        foreach ($this->uniqueSkippedFiles($skippedFiles) as $item) {
            $reason = $item['reason'] ?? 'unsupported';
            $name = trim((string) ($item['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            if (!isset($groups[$reason])) {
                $groups[$reason] = [
                    'names' => [],
                    'details' => [],
                ];
            }

            $groups[$reason]['names'][] = $name;

            $detail = trim((string) ($item['detail'] ?? ''));
            // Keep conversion failures user-friendly in the UI while the
            // technical decoder output still goes to the server logs.
            if ($detail !== '' && $reason !== 'conversion') {
                $groups[$reason]['details'][$detail] = true;
            }
        }

        $result = [];

        foreach ($groups as $reason => $groupData) {
            $names = $groupData['names'];
            $details = array_keys($groupData['details']);

            if (count($names) > self::MAX_SKIPPED_NAMES_IN_MESSAGE) {
                $remaining = count($names) - self::MAX_SKIPPED_NAMES_IN_MESSAGE;
                $names = array_slice($names, 0, self::MAX_SKIPPED_NAMES_IN_MESSAGE);
                $names[] = '+' . $remaining . ' more';
            }

            $result[] = [
                'reason' => $reason,
                'label' => $this->translateSkipReason($reason),
                'names' => $names,
                'details' => array_slice($details, 0, 3),
            ];
        }

        return $result;
    }

    private function uniqueSkippedFiles(array $skippedFiles): array
    {
        $unique = [];

        foreach ($skippedFiles as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? 'unsupported'));
            $detail = trim((string) ($item['detail'] ?? ''));

            if ($name === '') {
                continue;
            }

            $key = $reason . '|' . $name;
            $unique[$key] = ['name' => $name, 'reason' => $reason, 'detail' => $detail];
        }

        return array_values($unique);
    }

    private function mapUploadExceptionToReason(\RuntimeException $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === $this->translator->trans('properties.error_upload_heic')) {
            return 'heic';
        }

        if (str_starts_with($message, $this->translator->trans('properties.error_upload_conversion'))) {
            return 'conversion';
        }

        if ($message === $this->translator->trans('properties.error_upload_storage')) {
            return 'storage';
        }

        return 'unsupported';
    }

    private function extractUploadExceptionDetail(\RuntimeException $exception, bool $stripDebugPrefix = false): ?string
    {
        $message = trim($exception->getMessage());
        $conversionPrefix = $this->translator->trans('properties.error_upload_conversion');

        if (str_starts_with($message, $conversionPrefix) && $message !== $conversionPrefix) {
            $detail = trim(substr($message, strlen($conversionPrefix)));

            if ($stripDebugPrefix && str_starts_with($detail, 'DEBUG:')) {
                $detail = trim(substr($detail, strlen('DEBUG:')));
            }

            return $detail;
        }

        $debugMarker = 'DEBUG:';
        $debugPosition = strpos($message, $debugMarker);

        if ($debugPosition !== false) {
            $detail = trim(substr($message, $debugPosition + strlen($debugMarker)));

            if ($detail !== '') {
                return $detail;
            }
        }

        return null;
    }

    private function invalidUploadException(string $debugDetail): \RuntimeException
    {
        return new \RuntimeException(
            $this->translator->trans('properties.error_upload_invalid')
            . ' DEBUG: '
            . $debugDetail
        );
    }

    private function translateSkipReason(string $reason): string
    {
        return match ($reason) {
            'duplicate' => $this->translator->trans('properties.upload_skip_reason_duplicate'),
            'conversion' => $this->translator->trans('properties.error_upload_conversion'),
            'heic' => $this->translator->trans('properties.error_upload_heic'),
            'storage' => $this->translator->trans('properties.error_upload_storage'),
            'too_large' => $this->translator->trans('properties.upload_skip_reason_too_large'),
            'upload_error' => $this->translator->trans('properties.upload_skip_reason_upload_error'),
            default => $this->translator->trans('properties.upload_skip_reason_unsupported'),
        };
    }

    private function buildUploadSkippedGroupsFromMessageContext(string $message): ?array
    {
        if (!str_starts_with($message, $this->translator->trans('properties.error_upload_none_added_prefix'))) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $message) ?: [];
        if (count($lines) <= 1) {
            return null;
        }

        $groups = [];
        $currentGroup = null;

        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_ends_with($line, ':')) {
                $currentGroup = rtrim($line, ':');
                $groups[] = ['label' => $currentGroup, 'names' => [], 'details' => []];
                continue;
            }

            if ($currentGroup !== null && str_starts_with($line, '- ')) {
                $groups[array_key_last($groups)]['names'][] = substr($line, 2);
                continue;
            }

            if ($currentGroup !== null && str_starts_with($line, '! ')) {
                $groups[array_key_last($groups)]['details'][] = substr($line, 2);
            }
        }

        if ($groups === []) {
            return null;
        }

        return [
            'title' => $this->translator->trans('properties.error_upload_none_added_prefix'),
            'groups' => $groups,
        ];
    }

    private function copyUploadedFileToTemporaryPath(UploadedFileInterface $uploadedFile): string
    {
        $originalName = $this->uploadedFileDisplayName($uploadedFile);
        $temporaryPath = $this->createManagedTemporaryFile('property_media_', $originalName, 'Could not create a temporary file for uploaded media.');

        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $destination = @fopen($temporaryPath, 'wb');

        if ($destination === false) {
            throw $this->invalidUploadException('Could not open the temporary upload destination for writing: ' . $temporaryPath);
        }

        while (!$stream->eof()) {
            fwrite($destination, $stream->read(8192));
        }

        fclose($destination);
        $stream->rewind();

        return $temporaryPath;
    }

    private function writeTemporaryBinaryFile(string $binary, string $originalName): string
    {
        $temporaryPath = $this->createManagedTemporaryFile(
            'property_zip_media_',
            $originalName,
            'Could not create a temporary file for ZIP media entry "' . $originalName . '".'
        );

        if (@file_put_contents($temporaryPath, $binary) === false) {
            throw $this->invalidUploadException('Could not write the ZIP media entry "' . $originalName . '" to temporary storage.');
        }

        return $temporaryPath;
    }

    private function storeMediaFileFromPath(
        string $sourcePath,
        string $originalName,
        ?string $progressToken = null,
        int $currentUnit = 0,
        int $totalUnits = 1,
        ?string $displayName = null
    ): string
    {
        $extension = $this->detectMediaExtensionFromPath($sourcePath, $originalName);
        $displayName = $displayName ?? $originalName;

        $this->logPropertyConversionDebug(
            'Upload pipeline entered for "'
            . $displayName
            . '" (original: '
            . $originalName
            . ', detected extension: '
            . ($extension ?? '[none]')
            . ', source path: '
            . $sourcePath
            . ', exists: '
            . (is_file($sourcePath) ? 'yes' : 'no')
            . ', size: '
            . (is_file($sourcePath) ? (string) filesize($sourcePath) : '[missing]')
            . ')'
        );

        if ($extension !== null) {
            if (in_array($extension, ['heic', 'avif'], true)) {
                $canConvertImage = $this->canAttemptImageMagickConversion($sourcePath, $originalName);

                $this->logPropertyConversionDebug(
                    strtoupper($extension)
                    . ' upload detected for "'
                    . $displayName
                    . '". ImageMagick available: '
                    . ($this->resolveImageMagickBinary() !== null ? 'yes' : 'no')
                    . '. Conversion allowed: '
                    . ($canConvertImage ? 'yes' : 'no')
                );

                if (!$canConvertImage) {
                    throw new \RuntimeException($this->translator->trans('properties.error_upload_heic'));
                }

                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_converting_phase'),
                    $this->translator->trans('properties.upload_progress_converting_message') . ' ' . $displayName,
                    $currentUnit,
                    $totalUnits
                );

                return $this->convertMediaWithAvailableTools($sourcePath, $progressToken, $currentUnit, $totalUnits, $displayName, $extension);
            }

            if ($this->shouldOptimizeOversizedImage($sourcePath, $extension)) {
                $this->updateUploadProgress(
                    $progressToken,
                    'processing',
                    $this->translator->trans('properties.upload_progress_converting_phase'),
                    $this->translator->trans('properties.upload_progress_optimizing_message') . ' ' . $displayName,
                    $currentUnit,
                    $totalUnits
                );

                return $this->convertMediaWithAvailableTools($sourcePath, $progressToken, $currentUnit, $totalUnits, $displayName);
            }

            $this->updateUploadProgress(
                $progressToken,
                'processing',
                $this->translator->trans('properties.upload_progress_saving_phase'),
                $this->translator->trans('properties.upload_progress_saving_message') . ' ' . $displayName,
                $currentUnit,
                $totalUnits
            );
            return $this->storeManagedMediaFile($sourcePath, $extension);
        }

        $canConvertUnknown = $this->canAttemptImageMagickConversion($sourcePath, $originalName);

        $this->logPropertyConversionDebug(
            'Unknown image upload for "'
            . $displayName
            . '". Conversion fallback allowed: '
            . ($canConvertUnknown ? 'yes' : 'no')
        );

        if (!$canConvertUnknown) {
            throw $this->invalidUploadException(
                'Media type could not be validated for "'
                . $displayName
                . '" (original: '
                . $originalName
                . ', detected extension: '
                . ($extension ?? '[none]')
                . ').'
            );
        }

        $this->updateUploadProgress(
            $progressToken,
            'processing',
            $this->translator->trans('properties.upload_progress_converting_phase'),
            $this->translator->trans('properties.upload_progress_converting_message') . ' ' . $displayName,
            $currentUnit,
            $totalUnits
        );

        return $this->convertMediaWithAvailableTools($sourcePath, $progressToken, $currentUnit, $totalUnits, $displayName);
    }

    private function storeManagedMediaFile(string $sourcePath, string $extension): string
    {
        $uploadDirectory = $this->propertyUploadsDirectory();

        if (!is_dir($uploadDirectory) && !@mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_storage'));
        }

        if (!is_writable($uploadDirectory)) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_storage'));
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destinationPath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;

        if (!@copy($sourcePath, $destinationPath)) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_storage'));
        }

        return $this->basePath . '/uploads/properties/' . $filename;
    }

    private function propertyUploadsDirectory(): string
    {
        return dirname(__DIR__, 2) . '/public/uploads/properties';
    }

    private function canAttemptImageMagickConversion(string $sourcePath, string $originalName): bool
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (in_array($extension, ['mp4', 'mov', 'webm', 'zip'], true)) {
            return false;
        }

        $header = @file_get_contents($sourcePath, false, null, 0, 64);

        if (!is_string($header) || $header === '') {
            return false;
        }

        if (str_starts_with($header, "\x1A\x45\xDF\xA3")) {
            return false;
        }

        if (strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($header, 8, 4));

            if (in_array($brand, ['qt  ', 'isom', 'iso2', 'avc1', 'mp41', 'mp42', 'm4v ', '3gp4', '3gp5', '3gp6', 'msnv'], true)) {
                return false;
            }
        }

        return $this->resolveImageMagickBinary() !== null
            || $this->resolveHeifConvertBinary() !== null
            || $this->resolveFfmpegBinary() !== null;
    }

    private function shouldOptimizeOversizedImage(string $sourcePath, string $extension): bool
    {
        $extension = strtolower($extension);

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return false;
        }

        $fileSize = @filesize($sourcePath);

        if (!is_int($fileSize)) {
            return false;
        }

        $threshold = $extension === 'png'
            ? self::PNG_OPTIMIZE_THRESHOLD_BYTES
            : self::IMAGE_OPTIMIZE_THRESHOLD_BYTES;

        if ($fileSize <= $threshold) {
            return false;
        }

        return $this->resolveImageMagickBinary() !== null;
    }

    private function convertMediaWithImageMagick(
        string $sourcePath,
        ?string $progressToken = null,
        int $currentUnit = 0,
        int $totalUnits = 1,
        ?string $displayName = null,
        ?string $sourceFormatHint = null
    ): string
    {
        $magickBinary = $this->resolveImageMagickBinary();

        if ($magickBinary === null) {
            throw $this->invalidUploadException('ImageMagick is unavailable for converting "' . ($displayName ?? basename($sourcePath)) . '".');
        }

        [$magickSourcePath, $cleanupSourcePath] = $this->prepareImageMagickSourcePath($sourcePath, $sourceFormatHint);
        $magickInputCandidates = $this->buildImageMagickInputCandidates($magickSourcePath, $sourceFormatHint);

        $temporaryTarget = $this->createManagedTemporaryFile(
            'property_converted_',
            ($displayName ?? basename($sourcePath)) . '.jpg',
            'Could not create a temporary conversion target for "' . ($displayName ?? basename($sourcePath)) . '".'
        );
        $resizeGeometry = '2400x2400>';
        $attemptErrors = [];
        $conversionSucceeded = false;

        foreach ($magickInputCandidates as $magickInputPath) {
            if (is_file($temporaryTarget)) {
                @unlink($temporaryTarget);
            }

            $command = [
                $magickBinary,
                $magickInputPath,
                '-auto-orient',
                '-strip',
                '-resize',
                $resizeGeometry,
                '-sampling-factor',
                '4:2:0',
                '-quality',
                '88',
                $temporaryTarget,
            ];

            $output = [];
            $exitCode = $this->runProcessCommand($command, $output);
            $debugOutput = trim(implode(PHP_EOL, array_filter($output, static fn ($line) => is_string($line) && trim($line) !== '')));

            if ($exitCode === 0 && is_file($temporaryTarget) && filesize($temporaryTarget) > 0) {
                $conversionSucceeded = true;
                break;
            }

            $attemptErrors[] = 'input ' . $magickInputPath . ' (exit ' . $exitCode . '): ' . ($debugOutput !== '' ? $debugOutput : '[no process output]');
        }

        if (!$conversionSucceeded) {
            $combinedDebugOutput = implode(' || ', $attemptErrors);

            $this->logPropertyConversionDebug(
                'Property media conversion failed for "'
                . ($displayName ?? basename($sourcePath))
                . '" after '
                . count($magickInputCandidates)
                . ' attempt(s): '
                . ($combinedDebugOutput !== '' ? $combinedDebugOutput : '[no process output]')
            );

            if ($cleanupSourcePath !== null && is_file($cleanupSourcePath)) {
                @unlink($cleanupSourcePath);
            }

            if (is_file($temporaryTarget)) {
                @unlink($temporaryTarget);
            }

            throw $this->buildFriendlyConversionException(
                'ImageMagick conversion failed for "'
                . ($displayName ?? basename($sourcePath))
                . '": '
                . ($combinedDebugOutput !== '' ? $combinedDebugOutput : '[no process output]')
            );
        }

        try {
            $this->updateUploadProgress(
                $progressToken,
                'processing',
                $this->translator->trans('properties.upload_progress_saving_phase'),
                $this->translator->trans('properties.upload_progress_saving_message') . ' ' . ($displayName ?? basename($sourcePath)),
                $currentUnit,
                $totalUnits
            );
            return $this->storeManagedMediaFile($temporaryTarget, 'jpg');
        } finally {
            if ($cleanupSourcePath !== null && is_file($cleanupSourcePath)) {
                @unlink($cleanupSourcePath);
            }

            if (is_file($temporaryTarget)) {
                @unlink($temporaryTarget);
            }
        }
    }

    private function convertMediaWithAvailableTools(
        string $sourcePath,
        ?string $progressToken = null,
        int $currentUnit = 0,
        int $totalUnits = 1,
        ?string $displayName = null,
        ?string $sourceFormatHint = null
    ): string
    {
        $sourceFormatHint = $sourceFormatHint !== null ? strtolower(trim($sourceFormatHint)) : null;
        $attemptErrors = [];

        if (in_array($sourceFormatHint, ['heic', 'avif'], true)) {
            if ($this->resolveHeifConvertBinary() !== null) {
                try {
                    return $this->convertMediaWithHeifConvert(
                        $sourcePath,
                        $progressToken,
                        $currentUnit,
                        $totalUnits,
                        $displayName
                    );
                } catch (\RuntimeException $exception) {
                    $attemptErrors[] = 'heif-convert: ' . $this->extractUploadExceptionDetail($exception, true);
                }
            }

            if ($this->resolveImageMagickBinary() !== null) {
                try {
                    return $this->convertMediaWithImageMagick(
                        $sourcePath,
                        $progressToken,
                        $currentUnit,
                        $totalUnits,
                        $displayName,
                        $sourceFormatHint
                    );
                } catch (\RuntimeException $exception) {
                    $attemptErrors[] = 'magick: ' . $this->extractUploadExceptionDetail($exception, true);
                }
            }

            throw $this->buildFriendlyConversionException(
                strtoupper((string) $sourceFormatHint)
                . ' conversion failed for "'
                . ($displayName ?? basename($sourcePath))
                . '". Tried: '
                . (!empty($attemptErrors) ? implode(' || ', $attemptErrors) : '[no converter available]')
            );
        }

        if ($this->resolveImageMagickBinary() !== null) {
            try {
                return $this->convertMediaWithImageMagick(
                    $sourcePath,
                    $progressToken,
                    $currentUnit,
                    $totalUnits,
                    $displayName,
                    $sourceFormatHint
                );
            } catch (\RuntimeException $exception) {
                $attemptErrors[] = 'magick: ' . $this->extractUploadExceptionDetail($exception, true);
            }
        }

        if ($this->resolveFfmpegBinary() !== null) {
            try {
                return $this->convertMediaWithFfmpeg(
                    $sourcePath,
                    $progressToken,
                    $currentUnit,
                    $totalUnits,
                    $displayName
                );
            } catch (\RuntimeException $exception) {
                $attemptErrors[] = 'ffmpeg: ' . $this->extractUploadExceptionDetail($exception, true);
            }
        }

        throw $this->buildFriendlyConversionException(
            'Media conversion failed for "'
            . ($displayName ?? basename($sourcePath))
            . '". Tried: '
            . (!empty($attemptErrors) ? implode(' || ', $attemptErrors) : '[no converter available]')
        );
    }

    private function convertMediaWithHeifConvert(
        string $sourcePath,
        ?string $progressToken = null,
        int $currentUnit = 0,
        int $totalUnits = 1,
        ?string $displayName = null
    ): string
    {
        $binary = $this->resolveHeifConvertBinary();

        if ($binary === null) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        $temporaryTarget = $this->createManagedTemporaryFile(
            'property_heif_convert_',
            ($displayName ?? basename($sourcePath)) . '.jpg',
            'Could not create a temporary HEIF conversion target for "' . ($displayName ?? basename($sourcePath)) . '".'
        );
        $command = [$binary, $sourcePath, $temporaryTarget];
        $output = [];
        $exitCode = $this->runProcessCommand($command, $output);
        $debugOutput = trim(implode(PHP_EOL, array_filter($output, static fn ($line) => is_string($line) && trim($line) !== '')));

        if ($exitCode !== 0 || !is_file($temporaryTarget) || filesize($temporaryTarget) <= 0) {
            if (is_file($temporaryTarget)) {
                @unlink($temporaryTarget);
            }

            throw $this->buildFriendlyConversionException(
                'heif-convert failed for "'
                . ($displayName ?? basename($sourcePath))
                . '" using input '
                . $sourcePath
                . ' (exit '
                . $exitCode
                . '): '
                . ($debugOutput !== '' ? $debugOutput : '[no process output]')
            );
        }

        try {
            if ($this->resolveImageMagickBinary() !== null) {
                return $this->convertMediaWithImageMagick(
                    $temporaryTarget,
                    $progressToken,
                    $currentUnit,
                    $totalUnits,
                    $displayName,
                    'jpg'
                );
            }

            $this->updateUploadProgress(
                $progressToken,
                'processing',
                $this->translator->trans('properties.upload_progress_saving_phase'),
                $this->translator->trans('properties.upload_progress_saving_message') . ' ' . ($displayName ?? basename($sourcePath)),
                $currentUnit,
                $totalUnits
            );

            return $this->storeManagedMediaFile($temporaryTarget, 'jpg');
        } finally {
            if (is_file($temporaryTarget)) {
                @unlink($temporaryTarget);
            }
        }
    }

    private function convertMediaWithFfmpeg(
        string $sourcePath,
        ?string $progressToken = null,
        int $currentUnit = 0,
        int $totalUnits = 1,
        ?string $displayName = null
    ): string
    {
        $binary = $this->resolveFfmpegBinary();

        if ($binary === null) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        $temporaryTarget = $this->createManagedTemporaryFile(
            'property_ffmpeg_convert_',
            ($displayName ?? basename($sourcePath)) . '.jpg',
            'Could not create a temporary FFmpeg conversion target for "' . ($displayName ?? basename($sourcePath)) . '".'
        );
        $command = [
            $binary,
            '-y',
            '-i',
            $sourcePath,
            '-frames:v',
            '1',
            '-vf',
            'scale=w=2400:h=2400:force_original_aspect_ratio=decrease',
            '-q:v',
            '2',
            $temporaryTarget,
        ];

        $output = [];
        $exitCode = $this->runProcessCommand($command, $output);
        $debugOutput = trim(implode(PHP_EOL, array_filter($output, static fn ($line) => is_string($line) && trim($line) !== '')));

        if ($exitCode !== 0 || !is_file($temporaryTarget) || filesize($temporaryTarget) <= 0) {
            if (is_file($temporaryTarget)) {
                @unlink($temporaryTarget);
            }

            throw $this->buildFriendlyConversionException(
                'ffmpeg conversion failed for "'
                . ($displayName ?? basename($sourcePath))
                . '" using input '
                . $sourcePath
                . ' (exit '
                . $exitCode
                . '): '
                . ($debugOutput !== '' ? $debugOutput : '[no process output]')
            );
        }

        try {
            if ($this->resolveImageMagickBinary() !== null) {
                return $this->convertMediaWithImageMagick(
                    $temporaryTarget,
                    $progressToken,
                    $currentUnit,
                    $totalUnits,
                    $displayName,
                    'jpg'
                );
            }

            $this->updateUploadProgress(
                $progressToken,
                'processing',
                $this->translator->trans('properties.upload_progress_saving_phase'),
                $this->translator->trans('properties.upload_progress_saving_message') . ' ' . ($displayName ?? basename($sourcePath)),
                $currentUnit,
                $totalUnits
            );

            return $this->storeManagedMediaFile($temporaryTarget, 'jpg');
        } finally {
            if (is_file($temporaryTarget)) {
                @unlink($temporaryTarget);
            }
        }
    }

    private function prepareImageMagickSourcePath(string $sourcePath, ?string $sourceFormatHint = null): array
    {
        $sourceFormatHint = $sourceFormatHint !== null ? strtolower(trim($sourceFormatHint)) : null;

        if ($sourceFormatHint === null || $sourceFormatHint === '') {
            return [$sourcePath, null];
        }

        $currentExtension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        if ($currentExtension === $sourceFormatHint) {
            return [$sourcePath, null];
        }

        try {
            $extendedTemporarySource = $this->createManagedTemporaryFile(
                'property_magick_source_',
                'source.' . $sourceFormatHint,
                'Could not create an ImageMagick source staging file.'
            );
        } catch (\RuntimeException) {
            return [$sourcePath, null];
        }

        if (!@copy($sourcePath, $extendedTemporarySource)) {
            @unlink($extendedTemporarySource);

            return [$sourcePath, null];
        }

        return [$extendedTemporarySource, $extendedTemporarySource];
    }

    private function buildImageMagickInputCandidates(string $sourcePath, ?string $sourceFormatHint = null): array
    {
        $sourceFormatHint = $sourceFormatHint !== null ? strtolower(trim($sourceFormatHint)) : null;
        $candidates = [$sourcePath];

        if (in_array($sourceFormatHint, ['heic', 'avif'], true) && PHP_OS_FAMILY === 'Linux') {
            $candidates[] = $sourceFormatHint . ':' . $sourcePath;
        }

        return array_values(array_unique($candidates));
    }

    private function runProcessCommand(array $command, array &$output): int
    {
        $output = [];
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $processCommand = $this->buildProcessCommand($command);
        $process = @proc_open($processCommand, $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->logPropertyConversionDebug(
                'Property media conversion process could not start. Command: '
                . json_encode($command, JSON_UNESCAPED_SLASHES)
                . ' | Prepared: '
                . (is_string($processCommand) ? $processCommand : json_encode($processCommand, JSON_UNESCAPED_SLASHES))
            );
            return $this->runExecFallbackCommand($command, $output);
        }

        try {
            $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
            $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        foreach ([$stdout, $stderr] as $streamOutput) {
            if (!is_string($streamOutput) || $streamOutput === '') {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', $streamOutput) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $output[] = $line;
                }
            }
        }

        return proc_close($process);
    }

    private function buildProcessCommand(array $command): array|string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return $command;
        }

        $escapedParts = array_map(
            static fn (mixed $part): string => escapeshellarg((string) $part),
            $command
        );

        return implode(' ', $escapedParts);
    }

    private function runExecFallbackCommand(array $command, array &$output): int
    {
        $output = [];
        $commandString = implode(
            ' ',
            array_map(
                static fn (mixed $part): string => escapeshellarg((string) $part),
                $command
            )
        ) . ' 2>&1';

        $exitCode = 1;
        @exec($commandString, $output, $exitCode);

        $output = array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $output),
            static fn (string $line): bool => $line !== ''
        ));

        $this->logPropertyConversionDebug(
            'Property media conversion used exec fallback. Exit code: '
            . $exitCode
            . '. Command: '
            . $commandString
            . '. Output: '
            . ($output !== [] ? implode(' || ', $output) : '[no process output]')
        );

        return $exitCode;
    }

    private function logPropertyConversionDebug(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

        error_log($line);

        $uploadDirectory = $this->propertyUploadsDirectory();

        if (!is_dir($uploadDirectory)) {
            @mkdir($uploadDirectory, 0775, true);
        }

        if (is_dir($uploadDirectory) && is_writable($uploadDirectory)) {
            $debugLogPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'clemexique-property-upload.log';
            @file_put_contents($debugLogPath, $line, FILE_APPEND);
        }
    }

    private function buildFriendlyConversionException(string $debugMessage): \RuntimeException
    {
        $this->logPropertyConversionDebug($debugMessage);

        return new \RuntimeException($this->translator->trans('properties.error_upload_conversion'));
    }

    private function resolveImageMagickBinary(): ?string
    {
        static $resolved = false;

        if ($resolved !== false) {
            return $resolved;
        }

        $configuredBinary = $this->resolveConfiguredBinaryPath([
            'IMAGE_MAGICK_BINARY',
            'IMAGEMAGICK_BINARY',
            'MAGICK_BINARY',
        ], ['-version']);

        if ($configuredBinary !== null) {
            $resolved = $configuredBinary;

            return $resolved;
        }

        $candidates = [];

        foreach (['C:\\Program Files\\ImageMagick-*\\magick.exe', 'C:\\Program Files (x86)\\ImageMagick-*\\magick.exe'] as $pattern) {
            $matches = glob($pattern);

            if (is_array($matches)) {
                foreach ($matches as $match) {
                    $candidates[] = $match;
                }
            }
        }

        $candidates = array_merge(
            $candidates,
            [
                'magick',
                'convert',
                '/usr/bin/magick',
                '/usr/local/bin/magick',
                '/bin/magick',
                '/usr/bin/convert',
                '/usr/local/bin/convert',
                '/bin/convert',
                '/usr/bin/convert-im6',
                '/usr/bin/convert-im6.q16',
            ]
        );

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $output = [];
            $exitCode = 1;
            @exec(sprintf('%s -version 2>&1', escapeshellarg($candidate)), $output, $exitCode);

            if ($exitCode === 0) {
                $resolved = $candidate;

                return $resolved;
            }
        }

        $resolved = null;

        return null;
    }

    private function resolveHeifConvertBinary(): ?string
    {
        static $resolved = false;

        if ($resolved !== false) {
            return $resolved;
        }

        $configuredBinary = $this->resolveConfiguredBinaryPath([
            'HEIF_CONVERT_BINARY',
        ], ['--help']);

        if ($configuredBinary !== null) {
            $resolved = $configuredBinary;

            return $resolved;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $resolved = null;

            return null;
        }

        $candidates = [
            'heif-convert',
            '/usr/bin/heif-convert',
            '/usr/local/bin/heif-convert',
        ];

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $output = [];
            $exitCode = 1;
            @exec(sprintf('%s --help 2>&1', escapeshellarg($candidate)), $output, $exitCode);
            $combinedOutput = strtolower(trim(implode(' ', array_filter($output, static fn ($line) => is_string($line) && trim($line) !== ''))));

            if (($exitCode === 0 || $exitCode === 1) && str_contains($combinedOutput, 'heif-convert')) {
                $resolved = $candidate;

                return $resolved;
            }
        }

        $resolved = null;

        return null;
    }

    private function resolveConfiguredBinaryPath(array $environmentKeys, array $validationArguments): ?string
    {
        foreach ($environmentKeys as $environmentKey) {
            $candidate = trim((string) ($_ENV[$environmentKey] ?? getenv($environmentKey) ?: ''));

            if ($candidate === '') {
                continue;
            }

            $output = [];
            $exitCode = 1;
            @exec(
                implode(' ', array_map('escapeshellarg', array_merge([$candidate], $validationArguments))) . ' 2>&1',
                $output,
                $exitCode
            );

            if ($exitCode === 0 || ($validationArguments === ['--help'] && $exitCode === 1)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveFfmpegBinary(): ?string
    {
        static $resolved = false;

        if ($resolved !== false) {
            return $resolved;
        }

        $candidates = [
            'ffmpeg',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
        ];

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $output = [];
            $exitCode = 1;
            @exec(sprintf('%s -version 2>&1', escapeshellarg($candidate)), $output, $exitCode);

            if ($exitCode === 0) {
                $resolved = $candidate;

                return $resolved;
            }
        }

        $resolved = null;

        return null;
    }

    private function computeStoredMediaHash(string $mediaUrl): string
    {
        $filePath = $this->resolveManagedUploadPath($mediaUrl);

        if ($filePath === null || !is_file($filePath)) {
            return '';
        }

        $hash = hash_file('sha256', $filePath);

        return $hash === false ? '' : $hash;
    }

    private function deleteManagedUploadFile(string $mediaUrl): void
    {
        $filePath = $this->resolveManagedUploadPath($mediaUrl);

        if ($filePath !== null && is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function countUploadProcessingUnits(array $files): int
    {
        $totalUnits = 0;

        foreach ($files as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFileInterface || $uploadedFile->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($this->isZipUpload($uploadedFile)) {
                $totalUnits += $this->countZipEntries($uploadedFile);
                continue;
            }

            $totalUnits++;
        }

        return $totalUnits;
    }

    private function countZipEntries(UploadedFileInterface $uploadedFile): int
    {
        $temporaryZipPath = $this->copyUploadedFileToTemporaryPath($uploadedFile);

        try {
            return $this->countZipEntriesFromPath($temporaryZipPath);
        } finally {
            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
        }
    }

    private function countZipEntriesFromPath(string $temporaryZipPath): int
    {
        $zip = new \ZipArchive();
        $count = 0;

        if ($zip->open($temporaryZipPath) !== true) {
            return 1;
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = (string) $zip->getNameIndex($index);

                if ($entryName === '' || str_ends_with($entryName, '/')) {
                    continue;
                }

                $count++;
            }
        } finally {
            $zip->close();
        }

        return max(1, $count);
    }

    private function extractDirectUploadManifest(array $payload): array
    {
        $rawManifest = trim((string) ($payload['direct_upload_manifest'] ?? ''));

        if ($rawManifest === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawManifest, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException($this->translator->trans('properties.error_direct_upload_manifest_invalid'));
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException($this->translator->trans('properties.error_direct_upload_manifest_invalid'));
        }

        $manifest = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException($this->translator->trans('properties.error_direct_upload_manifest_invalid'));
            }

            $key = trim((string) ($entry['key'] ?? ''));
            $name = trim((string) ($entry['name'] ?? ''));

            if ($key === '' || $name === '') {
                throw new \InvalidArgumentException($this->translator->trans('properties.error_direct_upload_manifest_invalid'));
            }

            $manifest[] = [
                'key' => $key,
                'name' => $name,
                'type' => trim((string) ($entry['type'] ?? '')),
            ];
        }

        return $manifest;
    }

    private function isDirectUploadEnabled(): bool
    {
        return $this->directUploadSettings() !== null;
    }

    private function directUploadSettings(): ?array
    {
        $accountId = trim((string) ($_ENV['CLOUDFLARE_R2_ACCOUNT_ID'] ?? ''));
        $accessKeyId = trim((string) ($_ENV['CLOUDFLARE_R2_ACCESS_KEY_ID'] ?? ''));
        $secretAccessKey = trim((string) ($_ENV['CLOUDFLARE_R2_SECRET_ACCESS_KEY'] ?? ''));
        $bucket = trim((string) ($_ENV['CLOUDFLARE_R2_BUCKET'] ?? ''));

        if ($accountId === '' || $accessKeyId === '' || $secretAccessKey === '' || $bucket === '') {
            return null;
        }

        return [
            'account_id' => $accountId,
            'access_key_id' => $accessKeyId,
            'secret_access_key' => $secretAccessKey,
            'bucket' => $bucket,
            'region' => trim((string) ($_ENV['CLOUDFLARE_R2_REGION'] ?? 'auto')) ?: 'auto',
            'prefix' => trim((string) ($_ENV['CLOUDFLARE_R2_UPLOAD_PREFIX'] ?? 'property-staging'), '/'),
        ];
    }

    private function shouldUseMultipartDirectUpload(int $size, string $name = ''): bool
    {
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip') {
            return true;
        }

        return $size >= self::DIRECT_UPLOAD_MULTIPART_THRESHOLD_BYTES;
    }

    private function isDirectUploadObjectKeyAllowed(string $objectKey): bool
    {
        $settings = $this->directUploadSettings();

        if ($settings === null) {
            return false;
        }

        $prefix = trim((string) ($settings['prefix'] ?? ''), '/');

        if ($prefix === '') {
            return false;
        }

        return str_starts_with(ltrim($objectKey, '/'), $prefix . '/');
    }

    private function normalizeDirectUploadContentType(string $contentType, string $name): ?string
    {
        $normalizedType = strtolower(trim($contentType));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/heic',
            'image/heif',
            'image/avif',
            'video/mp4',
            'video/quicktime',
            'video/webm',
            'application/zip',
            'application/x-zip-compressed',
        ];

        if (in_array($normalizedType, $allowedTypes, true)) {
            return $normalizedType === 'application/x-zip-compressed' ? 'application/zip' : $normalizedType;
        }

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'heic', 'heif' => 'image/heic',
            'avif' => 'image/avif',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'zip' => 'application/zip',
            default => null,
        };
    }

    private function buildDirectUploadObjectKey(string $name, int $index = 0): string
    {
        $settings = $this->directUploadSettings();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extensionSuffix = $extension !== '' ? '.' . preg_replace('/[^a-z0-9]+/i', '', $extension) : '';
        $prefix = $settings['prefix'] ?? 'property-staging';
        $datePath = gmdate('Y/m');

        return $prefix
            . '/'
            . $datePath
            . '/'
            . bin2hex(random_bytes(12))
            . '-'
            . $index
            . $extensionSuffix;
    }

    private function createDirectUploadSignedUrl(
        string $method,
        string $objectKey,
        int $expiresSeconds,
        ?string $contentType = null,
        array $extraQueryParameters = []
    ): string
    {
        $settings = $this->directUploadSettings();

        if ($settings === null) {
            throw new \RuntimeException($this->translator->trans('properties.error_direct_upload_not_configured'));
        }

        $service = 's3';
        $host = $settings['account_id'] . '.r2.cloudflarestorage.com';
        $region = $settings['region'];
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $canonicalUri = '/' . rawurlencode($settings['bucket']) . '/' . $this->encodeR2ObjectKey($objectKey);
        $signedHeaders = $contentType !== null ? 'content-type;host' : 'host';
        $headers = [
            'host' => $host,
        ];

        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        ksort($headers);

        $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
        $queryParameters = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $settings['access_key_id'] . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(60, min($expiresSeconds, 86400)),
            'X-Amz-SignedHeaders' => $signedHeaders,
        ];

        foreach ($extraQueryParameters as $key => $value) {
            $queryParameters[(string) $key] = (string) $value;
        }

        ksort($queryParameters);

        $canonicalHeaders = '';

        foreach ($headers as $headerName => $headerValue) {
            $canonicalHeaders .= strtolower($headerName) . ':' . trim((string) $headerValue) . "\n";
        }

        $canonicalQueryString = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);
        $canonicalRequest = strtoupper($method)
            . "\n"
            . $canonicalUri
            . "\n"
            . $canonicalQueryString
            . "\n"
            . $canonicalHeaders
            . "\n"
            . $signedHeaders
            . "\nUNSIGNED-PAYLOAD";

        $stringToSign = 'AWS4-HMAC-SHA256'
            . "\n"
            . $amzDate
            . "\n"
            . $credentialScope
            . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->buildAwsV4SigningKey($settings['secret_access_key'], $dateStamp, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return 'https://' . $host . $canonicalUri . '?' . $canonicalQueryString . '&X-Amz-Signature=' . $signature;
    }

    private function createMultipartDirectUpload(string $objectKey, string $contentType): string
    {
        $url = $this->createDirectUploadSignedUrl(
            'POST',
            $objectKey,
            self::DIRECT_UPLOAD_URL_TTL,
            $contentType,
            ['uploads' => '']
        );

        $result = $this->performDirectUploadHttpRequest('POST', $url, '', [
            'Content-Type: ' . $contentType,
        ]);

        if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
            throw new \RuntimeException($this->translator->trans('properties.error_direct_upload_prepare_failed'));
        }

        $uploadId = $this->extractXmlTagValue((string) ($result['body'] ?? ''), 'UploadId');

        if ($uploadId === '') {
            throw new \RuntimeException($this->translator->trans('properties.error_direct_upload_prepare_failed'));
        }

        return $uploadId;
    }

    private function buildMultipartDirectUploadPartTargets(string $objectKey, string $uploadId, int $size): array
    {
        $partSize = self::DIRECT_UPLOAD_MULTIPART_PART_SIZE;
        $partCount = max(1, (int) ceil(max(1, $size) / $partSize));
        $targets = [];

        for ($partNumber = 1; $partNumber <= $partCount; $partNumber++) {
            $targets[] = [
                'part_number' => $partNumber,
                'upload_url' => $this->createDirectUploadSignedUrl(
                    'PUT',
                    $objectKey,
                    self::DIRECT_UPLOAD_URL_TTL,
                    null,
                    [
                        'partNumber' => (string) $partNumber,
                        'uploadId' => $uploadId,
                    ]
                ),
            ];
        }

        return $targets;
    }

    private function listMultipartDirectUploadParts(string $objectKey, string $uploadId): array
    {
        $url = $this->createDirectUploadSignedUrl(
            'GET',
            $objectKey,
            self::DIRECT_UPLOAD_URL_TTL,
            null,
            ['uploadId' => $uploadId]
        );

        $result = $this->performDirectUploadHttpRequest('GET', $url);

        if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
            throw new \RuntimeException($this->translator->trans('properties.direct_upload_failed'));
        }

        $parts = $this->extractMultipartPartsFromXml((string) ($result['body'] ?? ''));

        if ($parts === []) {
            throw new \RuntimeException($this->translator->trans('properties.direct_upload_failed'));
        }

        return $parts;
    }

    private function completeMultipartDirectUpload(string $objectKey, string $uploadId, array $parts): void
    {
        $xml = $this->buildMultipartCompleteXml($parts);
        $url = $this->createDirectUploadSignedUrl(
            'POST',
            $objectKey,
            self::DIRECT_UPLOAD_URL_TTL,
            null,
            ['uploadId' => $uploadId]
        );

        $result = $this->performDirectUploadHttpRequest('POST', $url, $xml, [
            'Content-Type: application/xml',
        ]);

        if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
            throw new \RuntimeException($this->translator->trans('properties.direct_upload_failed'));
        }
    }

    private function abortMultipartDirectUpload(string $objectKey, string $uploadId): void
    {
        $url = $this->createDirectUploadSignedUrl(
            'DELETE',
            $objectKey,
            300,
            null,
            ['uploadId' => $uploadId]
        );

        $this->performDirectUploadHttpRequest('DELETE', $url);
    }

    private function buildAwsV4SigningKey(string $secretKey, string $dateStamp, string $region, string $service): string
    {
        $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    private function encodeR2ObjectKey(string $objectKey): string
    {
        $segments = array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', ltrim($objectKey, '/'))
        );

        return implode('/', $segments);
    }

    private function performDirectUploadHttpRequest(string $method, string $url, string $body = '', array $headers = []): array
    {
        $headerLines = array_merge([
            'Accept: application/xml, text/xml, */*',
        ], $headers);

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 120,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: [])
            : [];

        return [
            'status' => $this->extractHttpStatusCode($responseHeaders),
            'body' => is_string($responseBody) ? $responseBody : '',
            'headers' => $responseHeaders,
        ];
    }

    private function extractHttpStatusCode(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', (string) $headers[0], $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function extractXmlTagValue(string $xml, string $tagName): string
    {
        if ($xml === '' || $tagName === '') {
            return '';
        }

        if (function_exists('simplexml_load_string')) {
            $parsed = @simplexml_load_string($xml);

            if ($parsed !== false) {
                $result = $parsed->xpath('//*[local-name()="' . $tagName . '"]');

                if (is_array($result) && isset($result[0])) {
                    return trim((string) $result[0]);
                }
            }
        }

        if (preg_match('/<' . preg_quote($tagName, '/') . '>(.*?)<\/' . preg_quote($tagName, '/') . '>/s', $xml, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        return '';
    }

    private function extractMultipartPartsFromXml(string $xml): array
    {
        $parts = [];

        if ($xml === '') {
            return $parts;
        }

        if (function_exists('simplexml_load_string')) {
            $parsed = @simplexml_load_string($xml);

            if ($parsed !== false) {
                $nodes = $parsed->xpath('//*[local-name()="Part"]');

                if (is_array($nodes)) {
                    foreach ($nodes as $node) {
                        $partNumber = (int) ($node->PartNumber ?? 0);
                        $etag = trim((string) ($node->ETag ?? ''));

                        if ($partNumber > 0 && $etag !== '') {
                            $parts[] = [
                                'part_number' => $partNumber,
                                'etag' => $etag,
                            ];
                        }
                    }
                }
            }
        }

        if ($parts !== []) {
            usort($parts, static fn (array $left, array $right): int => $left['part_number'] <=> $right['part_number']);
            return $parts;
        }

        if (preg_match_all('/<Part>\s*<PartNumber>(\d+)<\/PartNumber>\s*<ETag>(.*?)<\/ETag>\s*<\/Part>/s', $xml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parts[] = [
                    'part_number' => (int) $match[1],
                    'etag' => trim(html_entity_decode($match[2], ENT_QUOTES | ENT_XML1, 'UTF-8')),
                ];
            }
        }

        usort($parts, static fn (array $left, array $right): int => $left['part_number'] <=> $right['part_number']);

        return $parts;
    }

    private function buildMultipartCompleteXml(array $parts): string
    {
        $xml = "<CompleteMultipartUpload>\n";

        foreach ($parts as $part) {
            $partNumber = (int) ($part['part_number'] ?? 0);
            $etag = trim((string) ($part['etag'] ?? ''));

            if ($partNumber <= 0 || $etag === '') {
                continue;
            }

            $xml .= "  <Part>\n";
            $xml .= '    <PartNumber>' . $partNumber . "</PartNumber>\n";
            $xml .= '    <ETag>' . htmlspecialchars($etag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</ETag>\n";
            $xml .= "  </Part>\n";
        }

        $xml .= "</CompleteMultipartUpload>";

        return $xml;
    }

    private function downloadDirectUploadedObjectToTemporaryPath(string $objectKey, string $originalName): string
    {
        $signedUrl = $this->createDirectUploadSignedUrl('GET', $objectKey, self::DIRECT_UPLOAD_URL_TTL);
        $temporaryPath = $this->createManagedTemporaryFile(
            'property_r2_',
            $originalName,
            'Could not create temporary storage for the direct-uploaded file "' . $originalName . '".'
        );

        $input = @fopen($signedUrl, 'rb');
        $output = @fopen($temporaryPath, 'wb');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }

            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw new \RuntimeException($this->translator->trans('properties.error_direct_upload_failed'));
        }

        try {
            stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }

        if (!is_file($temporaryPath) || (int) @filesize($temporaryPath) <= 0) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw new \RuntimeException($this->translator->trans('properties.error_direct_upload_failed'));
        }

        return $temporaryPath;
    }

    private function managedTemporaryDirectory(): string
    {
        $directory = dirname(__DIR__, 2) . '/var/upload-runtime';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_storage'));
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_storage'));
        }

        $this->cleanupManagedTemporaryFiles($directory);

        return $directory;
    }

    private function createManagedTemporaryFile(string $prefix, string $originalName = '', ?string $failureDebugMessage = null): string
    {
        $temporaryPath = tempnam($this->managedTemporaryDirectory(), $prefix);

        if ($temporaryPath === false) {
            throw $this->invalidUploadException($failureDebugMessage ?? ('Could not create a managed temporary file for "' . $originalName . '".'));
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== '') {
            $extendedPath = $temporaryPath . '.' . preg_replace('/[^a-z0-9]+/i', '', $extension);

            if (@rename($temporaryPath, $extendedPath)) {
                $temporaryPath = $extendedPath;
            }
        }

        return $temporaryPath;
    }

    private function cleanupManagedTemporaryFiles(string $directory): void
    {
        static $hasRun = false;

        if ($hasRun) {
            return;
        }

        $hasRun = true;
        $expirationSeconds = 6 * 3600;
        $now = time();
        $files = glob($directory . '/*');

        if (!is_array($files)) {
            return;
        }

        foreach ($files as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $lastModified = @filemtime($filePath);

            if ($lastModified !== false && ($now - $lastModified) > $expirationSeconds) {
                @unlink($filePath);
            }
        }
    }

    private function deleteDirectUploadedObject(string $objectKey): void
    {
        if ($objectKey === '' || !$this->isDirectUploadEnabled()) {
            return;
        }

        $signedUrl = $this->createDirectUploadSignedUrl('DELETE', $objectKey, 300);
        $context = stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        @file_get_contents($signedUrl, false, $context);
    }

    private function isZipFilePath(string $sourcePath, string $originalName = ''): bool
    {
        $header = @file_get_contents($sourcePath, false, null, 0, 4);

        if (is_string($header) && in_array($header, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            return true;
        }

        return strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) === 'zip';
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function sanitizeUploadProgressToken(string $token): ?string
    {
        $token = trim($token);

        if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{8,100}$/', $token)) {
            return null;
        }

        return $token;
    }

    private function initializeUploadProgress(?string $token): void
    {
        if ($token === null) {
            return;
        }

        $this->cleanupExpiredUploadProgressFiles();
        $this->writeUploadProgress($token, [
            'status' => 'uploaded',
            'phase' => $this->translator->trans('properties.upload_progress_waiting_phase'),
            'message' => $this->translator->trans('properties.upload_progress_waiting_message'),
            'percent' => 0,
            'current' => 0,
            'total' => 0,
            'updated_at' => time(),
        ]);
    }

    private function updateUploadProgress(?string $token, string $status, string $phase, string $message, int $current, int $total): void
    {
        if ($token === null) {
            return;
        }

        $total = max(1, $total);
        $current = max(0, min($current, $total));
        $percent = (int) round(($current / $total) * 100);

        $this->writeUploadProgress($token, [
            'status' => $status,
            'phase' => $phase,
            'message' => $message,
            'percent' => $percent,
            'current' => $current,
            'total' => $total,
            'updated_at' => time(),
        ]);
    }

    private function completeUploadProgress(?string $token, string $message): void
    {
        if ($token === null) {
            return;
        }

        $existing = $this->readUploadProgress($token) ?? [];
        $total = max(1, (int) ($existing['total'] ?? 1));

        $this->writeUploadProgress($token, [
            'status' => 'complete',
            'phase' => $this->translator->trans('properties.upload_progress_complete_phase'),
            'message' => $message,
            'percent' => 100,
            'current' => $total,
            'total' => $total,
            'updated_at' => time(),
        ]);
    }

    private function failUploadProgress(?string $token, string $message): void
    {
        if ($token === null) {
            return;
        }

        $existing = $this->readUploadProgress($token) ?? [];
        $total = max(1, (int) ($existing['total'] ?? 1));
        $current = min($total, max(0, (int) ($existing['current'] ?? 0)));

        $this->writeUploadProgress($token, [
            'status' => 'failed',
            'phase' => $this->translator->trans('properties.upload_progress_error_phase'),
            'message' => $message,
            'percent' => (int) round(($current / $total) * 100),
            'current' => $current,
            'total' => $total,
            'updated_at' => time(),
        ]);
    }

    private function readUploadProgress(string $token): ?array
    {
        $filePath = $this->uploadProgressFilePath($token);

        if (!is_file($filePath)) {
            return null;
        }

        $contents = @file_get_contents($filePath);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeUploadProgress(string $token, array $payload): void
    {
        $directory = $this->uploadProgressDirectory();

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        @file_put_contents($this->uploadProgressFilePath($token), json_encode($payload));
    }

    private function uploadProgressDirectory(): string
    {
        return dirname(__DIR__, 2) . '/var/upload-progress';
    }

    private function uploadProgressFilePath(string $token): string
    {
        return $this->uploadProgressDirectory() . '/' . $token . '.json';
    }

    private function cleanupExpiredUploadProgressFiles(): void
    {
        $directory = $this->uploadProgressDirectory();

        if (!is_dir($directory)) {
            return;
        }

        $now = time();
        $files = glob($directory . '/*.json');

        if (!is_array($files)) {
            return;
        }

        foreach ($files as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $lastModified = @filemtime($filePath);

            if ($lastModified !== false && ($now - $lastModified) > self::UPLOAD_PROGRESS_TTL) {
                @unlink($filePath);
            }
        }
    }

    private function shouldQueueDirectUploadImport(array $manifest): bool
    {
        foreach ($manifest as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = strtolower(trim((string) ($entry['name'] ?? '')));

            if ($name !== '' && str_ends_with($name, '.zip')) {
                return true;
            }
        }

        return false;
    }

    private function queuePropertyImportJob(string $mode, array $formData, array $directUploadManifest, ?int $propertyId = null): string
    {
        $jobId = 'property-import-' . bin2hex(random_bytes(12));
        $progressToken = 'import-' . bin2hex(random_bytes(12));

        $job = [
            'job_id' => $jobId,
            'mode' => $mode,
            'property_id' => $propertyId,
            'form_data' => $formData,
            'direct_upload_manifest' => $directUploadManifest,
            'progress_token' => $progressToken,
            'status' => 'queued',
            'message' => 'Upload complete. Import queued.',
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->writePropertyImportJob($jobId, $job);

        $_SESSION['property_admin_import_job_id'] = $jobId;

        return $jobId;
    }

    private function startBackgroundPropertyImportJob(string $jobId): void
    {
        $phpBinary = trim((string) ($_ENV['PROPERTY_IMPORT_PHP_BINARY'] ?? ''));

        if ($phpBinary === '') {
            $phpBinary = strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN' ? 'php.exe' : 'php';
        }

        $scriptPath = dirname(__DIR__, 2) . '/bin/process_property_import.php';

        if (!is_file($scriptPath)) {
            $this->logPropertyConversionDebug('Background property import script is missing: ' . $scriptPath);
            return;
        }

        if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
            $command = 'start /B "" ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($jobId);
            @pclose(@popen($command, 'r'));
            return;
        }

        $command = escapeshellcmd($phpBinary)
            . ' '
            . escapeshellarg($scriptPath)
            . ' '
            . escapeshellarg($jobId)
            . ' >/dev/null 2>&1 &';

        @exec($command);
    }

    private function consumeActivePropertyImportJobSummary(): ?array
    {
        $jobId = trim((string) ($_SESSION['property_admin_import_job_id'] ?? ''));

        if ($jobId === '') {
            return null;
        }

        $job = $this->readPropertyImportJob($jobId);

        if ($job === null) {
            unset($_SESSION['property_admin_import_job_id']);
            return null;
        }

        $status = (string) ($job['status'] ?? 'queued');

        if (in_array($status, ['complete', 'failed'], true)) {
            unset($_SESSION['property_admin_import_job_id']);
        }

        return [
            'job_id' => $jobId,
            'status' => $status,
            'message' => (string) ($job['message'] ?? ''),
        ];
    }

    private function propertyImportJobsDirectory(): string
    {
        $directory = dirname(__DIR__, 2) . '/var/property-import-jobs';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the property import jobs directory.');
        }

        return $directory;
    }

    private function propertyImportJobPath(string $jobId): string
    {
        return $this->propertyImportJobsDirectory() . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '', $jobId) . '.json';
    }

    private function writePropertyImportJob(string $jobId, array $job): void
    {
        file_put_contents(
            $this->propertyImportJobPath($jobId),
            (string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function readPropertyImportJob(string $jobId): ?array
    {
        $jobId = preg_replace('/[^A-Za-z0-9_-]+/', '', trim($jobId));

        if ($jobId === '') {
            return null;
        }

        $path = $this->propertyImportJobPath($jobId);

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function resumeSessionLock(): void
    {
        if (session_status() === PHP_SESSION_NONE && session_id() !== '') {
            @session_start();
        }
    }

    private function computeExistingImageHashes(array $imageUrls): array
    {
        $hashes = [];

        foreach ($imageUrls as $imageUrl) {
            $filePath = $this->resolveManagedUploadPath((string) $imageUrl);

            if ($filePath === null || !is_file($filePath)) {
                continue;
            }

            $hash = hash_file('sha256', $filePath);

            if ($hash !== false) {
                $hashes[$hash] = true;
            }
        }

        return $hashes;
    }

    private function resolveManagedUploadPath(string $imageUrl): ?string
    {
        $baseUploadPath = $this->basePath . '/uploads/properties/';
        $fallbackUploadPath = '/uploads/properties/';

        if (!str_starts_with($imageUrl, $baseUploadPath) && !str_starts_with($imageUrl, $fallbackUploadPath)) {
            return null;
        }

        return dirname(__DIR__, 2) . '/public/uploads/properties/' . basename($imageUrl);
    }

    private function normalizePropertyFormData(array $data): array
    {
        $listingMode = strtolower(trim((string) ($data['listing_mode'] ?? 'revente')));

        if ($listingMode === 'achat') {
            $listingMode = 'revente';
        }

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'listing_mode' => $listingMode,
            'price_amount' => trim((string) ($data['price_amount'] ?? '')),
            'price_currency' => trim((string) ($data['price_currency'] ?? 'CAD')),
        ];
    }

    private function requestExceedsPostLimit(Request $request): bool
    {
        $serverParams = $request->getServerParams();
        $contentLength = (int) ($serverParams['CONTENT_LENGTH'] ?? 0);
        $postMaxSize = $this->toBytes(ini_get('post_max_size'));

        return $postMaxSize > 0 && $contentLength > $postMaxSize;
    }

    private function toBytes(string|false $value): int
    {
        if ($value === false) {
            return 0;
        }

        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (float) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }
}
