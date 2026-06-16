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
    private const IMAGE_OPTIMIZE_THRESHOLD_BYTES = 10000000;
    private const UPLOAD_PROGRESS_TTL = 1800;

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
        $data = $this->normalizePropertyFormData((array) $request->getParsedBody());
        $queryParams = $request->getQueryParams();
        $progressToken = $this->sanitizeUploadProgressToken((string) ($queryParams['upload_progress_token'] ?? ($data['upload_progress_token'] ?? '')));
        unset($data['upload_progress_token']);

        if ($progressToken !== null) {
            $this->initializeUploadProgress($progressToken);
        }

        if ($this->requestExceedsPostLimit($request)) {
            $this->failUploadProgress($progressToken, $this->translator->trans('properties.error_upload_too_large'));
            return $this->renderAdminProperties($response, null, $this->translator->trans('properties.error_upload_too_large'), $data);
        }

        $this->releaseSessionLock();

        try {
            $uploadedFiles = $request->getUploadedFiles();
            $uploadOutcome = $this->storeUploadedImages($uploadedFiles['image_files'] ?? [], true, [], $progressToken);
            $imageUrls = $uploadOutcome['stored'];
            $data['image_url'] = $this->firstImageUrl($imageUrls) ?? '';

            if ($data['image_url'] === '') {
                throw new \RuntimeException($this->buildUploadFailureMessage($uploadOutcome['skipped']));
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
        $data = $this->normalizePropertyFormData((array) $request->getParsedBody());
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
            $uploadOutcome = $this->storeUploadedImages($uploadedFiles['image_files'] ?? [], false, $property['images'] ?? [], $progressToken);
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
                $skippedFiles[] = ['name' => $displayName, 'reason' => $this->mapUploadExceptionToReason($exception)];
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

        $zip = new \ZipArchive();
        $storedImages = [];
        $skippedFiles = [];

        try {
            if ($zip->open($temporaryZipPath) !== true) {
                throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = (string) $zip->getNameIndex($index);

                if ($entryName === '' || str_ends_with($entryName, '/')) {
                    continue;
                }

                $entryContents = $zip->getFromIndex($index);

                if (!is_string($entryContents) || $entryContents === '') {
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

                $temporaryEntryPath = $this->writeTemporaryBinaryFile($entryContents, $entryName);

                try {
                    $storedUrl = $this->storeMediaFileFromPath($temporaryEntryPath, $entryName, $progressToken, $currentUnit, $totalUnits, $entryName);
                } catch (\RuntimeException $exception) {
                    $skippedFiles[] = ['name' => $entryName, 'reason' => $this->mapUploadExceptionToReason($exception)];
                    $currentUnit++;
                    continue;
                } finally {
                    if (is_file($temporaryEntryPath)) {
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

            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
        }

        return [
            'stored' => $storedImages,
            'skipped' => $this->uniqueSkippedFiles($skippedFiles),
        ];
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
                IMAGETYPE_AVIF => defined('IMAGETYPE_AVIF') ? 'heic' : null,
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
                    'image/heic', 'image/heif', 'image/avif' => 'heic',
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
                        'image/heic', 'image/heif', 'image/avif' => 'heic',
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

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm'], true) && @filesize($sourcePath) > 0) {
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

            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1', 'avif'], true)) {
                return 'heic';
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

        return $this->twig->render($response, 'admin/properties.html.twig', [
            'page_title_key' => 'properties.page_title',
            'properties' => $this->propertyModel->findAll(),
            'editing_property' => $editingProperty,
            'form_error' => $error,
            'form_notice_groups' => $noticeGroups,
            'form_data' => $formData,
            'form_error_groups' => $error !== null ? $this->buildUploadSkippedGroupsFromMessageContext($error) : null,
        ]);
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
                $groups[$reason] = [];
            }

            $groups[$reason][] = $name;
        }

        $result = [];

        foreach ($groups as $reason => $names) {
            if (count($names) > self::MAX_SKIPPED_NAMES_IN_MESSAGE) {
                $remaining = count($names) - self::MAX_SKIPPED_NAMES_IN_MESSAGE;
                $names = array_slice($names, 0, self::MAX_SKIPPED_NAMES_IN_MESSAGE);
                $names[] = '+' . $remaining . ' more';
            }

            $result[] = [
                'reason' => $reason,
                'label' => $this->translateSkipReason($reason),
                'names' => $names,
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

            if ($name === '') {
                continue;
            }

            $key = $reason . '|' . $name;
            $unique[$key] = ['name' => $name, 'reason' => $reason];
        }

        return array_values($unique);
    }

    private function mapUploadExceptionToReason(\RuntimeException $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === $this->translator->trans('properties.error_upload_heic')) {
            return 'heic';
        }

        if ($message === $this->translator->trans('properties.error_upload_conversion')) {
            return 'conversion';
        }

        if ($message === $this->translator->trans('properties.error_upload_storage')) {
            return 'storage';
        }

        return 'unsupported';
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
                $groups[] = ['label' => $currentGroup, 'names' => []];
                continue;
            }

            if ($currentGroup !== null && str_starts_with($line, '- ')) {
                $groups[array_key_last($groups)]['names'][] = substr($line, 2);
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
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $temporaryPath = tempnam(sys_get_temp_dir(), 'property_media_');

        if ($temporaryPath === false) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        if ($extension !== '') {
            $extendedPath = $temporaryPath . '.' . preg_replace('/[^a-z0-9]+/i', '', $extension);

            if (@rename($temporaryPath, $extendedPath)) {
                $temporaryPath = $extendedPath;
            }
        }

        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $destination = @fopen($temporaryPath, 'wb');

        if ($destination === false) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
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
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $temporaryPath = tempnam(sys_get_temp_dir(), 'property_zip_media_');

        if ($temporaryPath === false) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        if ($extension !== '') {
            $extendedPath = $temporaryPath . '.' . preg_replace('/[^a-z0-9]+/i', '', $extension);

            if (@rename($temporaryPath, $extendedPath)) {
                $temporaryPath = $extendedPath;
            }
        }

        if (@file_put_contents($temporaryPath, $binary) === false) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
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

        if ($extension !== null) {
            if ($extension === 'heic') {
                if (!$this->canAttemptImageMagickConversion($sourcePath, $originalName)) {
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

                return $this->convertMediaWithImageMagick($sourcePath, $progressToken, $currentUnit, $totalUnits, $displayName, 'heic');
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

                return $this->convertMediaWithImageMagick($sourcePath, $progressToken, $currentUnit, $totalUnits, $displayName);
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

        if (!$this->canAttemptImageMagickConversion($sourcePath, $originalName)) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        $this->updateUploadProgress(
            $progressToken,
            'processing',
            $this->translator->trans('properties.upload_progress_converting_phase'),
            $this->translator->trans('properties.upload_progress_converting_message') . ' ' . $displayName,
            $currentUnit,
            $totalUnits
        );

        return $this->convertMediaWithImageMagick($sourcePath, $progressToken, $currentUnit, $totalUnits, $displayName);
    }

    private function storeManagedMediaFile(string $sourcePath, string $extension): string
    {
        $uploadDirectory = dirname(__DIR__, 2) . '/public/uploads/properties';

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

        return $this->resolveImageMagickBinary() !== null;
    }

    private function shouldOptimizeOversizedImage(string $sourcePath, string $extension): bool
    {
        if (!in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return false;
        }

        $fileSize = @filesize($sourcePath);

        if (!is_int($fileSize) || $fileSize <= self::IMAGE_OPTIMIZE_THRESHOLD_BYTES) {
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
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        [$magickSourcePath, $cleanupSourcePath] = $this->prepareImageMagickSourcePath($sourcePath, $sourceFormatHint);
        $magickInputPath = $this->buildImageMagickInputPath($magickSourcePath, $sourceFormatHint);

        $temporaryTarget = tempnam(sys_get_temp_dir(), 'property_converted_');

        if ($temporaryTarget === false) {
            throw new \RuntimeException($this->translator->trans('properties.error_upload_invalid'));
        }

        $temporaryTarget .= '.jpg';
        $command = sprintf(
            '%s %s -auto-orient -strip -resize 2400x2400^> -sampling-factor 4:2:0 -quality 88 %s 2>&1',
            escapeshellarg($magickBinary),
            escapeshellarg($magickInputPath),
            escapeshellarg($temporaryTarget)
        );

        $output = [];
        $exitCode = 1;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($temporaryTarget) || filesize($temporaryTarget) === 0) {
            $debugOutput = trim(implode(PHP_EOL, array_filter($output, static fn ($line) => is_string($line) && trim($line) !== '')));

            if ($debugOutput !== '') {
                error_log('Property media conversion failed for "' . ($displayName ?? basename($sourcePath)) . '": ' . $debugOutput);
            }

            if ($cleanupSourcePath !== null && is_file($cleanupSourcePath)) {
                @unlink($cleanupSourcePath);
            }

            if (is_file($temporaryTarget)) {
                @unlink($temporaryTarget);
            }

            throw new \RuntimeException($this->translator->trans('properties.error_upload_conversion'));
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

        $temporarySource = tempnam(sys_get_temp_dir(), 'property_magick_source_');

        if ($temporarySource === false) {
            return [$sourcePath, null];
        }

        $extendedTemporarySource = $temporarySource . '.' . preg_replace('/[^a-z0-9]+/i', '', $sourceFormatHint);

        if (!@copy($sourcePath, $extendedTemporarySource)) {
            @unlink($temporarySource);

            return [$sourcePath, null];
        }

        @unlink($temporarySource);

        return [$extendedTemporarySource, $extendedTemporarySource];
    }

    private function buildImageMagickInputPath(string $sourcePath, ?string $sourceFormatHint = null): string
    {
        $sourceFormatHint = $sourceFormatHint !== null ? strtolower(trim($sourceFormatHint)) : null;

        if ($sourceFormatHint === 'heic' && PHP_OS_FAMILY === 'Linux') {
            return 'heic:' . $sourcePath;
        }

        return $sourcePath;
    }

    private function resolveImageMagickBinary(): ?string
    {
        static $resolved = false;

        if ($resolved !== false) {
            return $resolved;
        }

        $candidates = [
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
        ];

        foreach (['C:\\Program Files\\ImageMagick-*\\magick.exe', 'C:\\Program Files (x86)\\ImageMagick-*\\magick.exe'] as $pattern) {
            $matches = glob($pattern);

            if (is_array($matches)) {
                foreach ($matches as $match) {
                    $candidates[] = $match;
                }
            }
        }

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
        $zip = new \ZipArchive();
        $count = 0;

        try {
            if ($zip->open($temporaryZipPath) !== true) {
                return 1;
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = (string) $zip->getNameIndex($index);

                if ($entryName === '' || str_ends_with($entryName, '/')) {
                    continue;
                }

                $count++;
            }
        } finally {
            $zip->close();

            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
        }

        return max(1, $count);
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
