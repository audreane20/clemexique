<?php

namespace App\Controller;

use App\Model\PropertyModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
        return $this->twig->render($response, 'admin/properties.html.twig', [
            'page_title' => 'Properties',
            'properties' => $this->propertyModel->findAll(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        $this->propertyModel->create($data);

        return $response
            ->withHeader('Location', $this->basePath . '/admin/properties')
            ->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();

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
}
