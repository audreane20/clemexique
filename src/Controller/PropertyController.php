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
            'page_title' => 'Properties',
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
            'page_title' => 'Properties',
            'properties' => $this->propertyModel->findAllByMode($mode),
            'selected_mode' => $mode,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $data['image_url'] = $this->storeUploadedImage($uploadedFiles['image_file'] ?? null);

        $this->propertyModel->create($data);

        return $response
            ->withHeader('Location', $this->basePath . '/admin/properties')
            ->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        if (($uploadedFiles['image_file'] ?? null) instanceof UploadedFileInterface
            && $uploadedFiles['image_file']->getError() === UPLOAD_ERR_OK) {
            $data['image_url'] = $this->storeUploadedImage($uploadedFiles['image_file']);
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

    private function storeUploadedImage(?UploadedFileInterface $uploadedFile): string
    {
        if (!$uploadedFile instanceof UploadedFileInterface || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Please upload a property image.');
        }

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
