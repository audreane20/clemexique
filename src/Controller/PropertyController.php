<?php

namespace App\Controller;

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

    public function __construct(PropertyModel $propertyModel, Twig $twig, string $basePath)
    {
        $this->propertyModel = $propertyModel;
        $this->twig = $twig;
        $this->basePath = $basePath;
    }

    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $editingProperty = null;

        if (!empty($queryParams['edit'])) {
            $editingProperty = $this->propertyModel->findById((int) $queryParams['edit']);
        }

        return $this->twig->render($response, 'admin/properties.html.twig', [
            'page_title_key' => 'properties.page_title',
            'properties' => $this->propertyModel->findAll(),
            'editing_property' => $editingProperty,
        ]);
    }

    public function publicIndex(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $mode = strtolower((string) ($queryParams['mode'] ?? 'all'));

        if (!in_array($mode, ['all', 'achat', 'location'], true)) {
            $mode = 'all';
        }

        return $this->twig->render($response, 'properties.html.twig', [
            'page_title_key' => 'properties.page_title',
            'properties' => $this->propertyModel->findAllByMode($mode),
            'selected_mode' => $mode,
        ]);
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
        $data = (array) $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $imageUrls = $this->storeUploadedImages($uploadedFiles['image_files'] ?? []);
        $data['image_url'] = $imageUrls[0] ?? '';

        $propertyId = $this->propertyModel->create($data);
        $this->propertyModel->replaceImages($propertyId, $imageUrls);

        return $response
            ->withHeader('Location', $this->basePath . '/admin/properties')
            ->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $property = $this->propertyModel->findById($id);

        if ($property === null) {
            throw new \InvalidArgumentException('Property not found.');
        }

        $data['image_url'] = $property['image_url'];
        $newImageUrls = $this->storeUploadedImages($uploadedFiles['image_files'] ?? [], false);

        if ($newImageUrls !== []) {
            $this->propertyModel->addImages($id, $newImageUrls);
        }

        $this->propertyModel->update($id, $data);

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

    private function storeUploadedImages(mixed $uploadedFiles, bool $required = true): array
    {
        $files = [];

        if ($uploadedFiles instanceof UploadedFileInterface) {
            $files = [$uploadedFiles];
        } elseif (is_array($uploadedFiles)) {
            $files = $uploadedFiles;
        }

        $storedImages = [];

        foreach ($files as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFileInterface) {
                continue;
            }

            if ($uploadedFile->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Please upload valid property images.');
            }

            $storedImages[] = $this->storeSingleUploadedImage($uploadedFile);
        }

        if ($required && $storedImages === []) {
            throw new \RuntimeException('Please upload at least one property image.');
        }

        return $storedImages;
    }

    private function storeSingleUploadedImage(UploadedFileInterface $uploadedFile): string
    {
        $mediaType = strtolower((string) $uploadedFile->getClientMediaType());
        $extension = $this->extensionFromMediaType($mediaType);

        if ($extension === null) {
            throw new \RuntimeException('Please upload a JPG, PNG, WEBP, or GIF image.');
        }

        $uploadDirectory = dirname(__DIR__, 2) . '/public/uploads/properties';

        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0775, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $uploadedFile->moveTo($uploadDirectory . DIRECTORY_SEPARATOR . $filename);

        return $this->basePath . '/uploads/properties/' . $filename;
    }

    private function extensionFromMediaType(string $mediaType): ?string
    {
        return match ($mediaType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }
}
