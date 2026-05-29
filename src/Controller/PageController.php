<?php

namespace App\Controller;

use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Helper\Translator;

class PageController
{
    public function __construct(
        private Twig $view,
        private Translator $translator
    ) {}

    public function home(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'home.html.twig', [
            'page_title' => $this->translator->trans('nav.home'),
        ]);
    }

    public function about(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'about.html.twig', [
            'page_title' => $this->translator->trans('nav.about'),
        ]);
    }

    public function contact(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'contact.html.twig', [
            'page_title' => $this->translator->trans('nav.contact'),
        ]);
    }
}
