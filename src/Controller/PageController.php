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
            'header_menu_links' => [
                [
                    'path' => '/',
                    'label' => $this->translator->trans('home.properties_title'),
                    'query' => 'mode=future_projet',
                    'fragment' => 'home-properties',
                ],
                [
                    'path' => '/demande-information',
                    'label' => $this->translator->trans('info_request.title'),
                ],
                [
                    'href' => 'https://saleah.stays.net/',
                    'label' => $this->translator->trans('info_request_rent.title'),
                    'external' => true,
                ],
                [
                    'path' => '/residence-immigration',
                    'label' => $this->translator->trans('info_request.secondary_title'),
                    'fragment' => 'home-residency',
                ],
                [
                    'path' => '/location-automobiles',
                    'label' => $this->translator->trans('auto_rental.title'),
                    'fragment' => 'home-rental',
                ],
                [
                    'path' => '/gestion-immobiliere',
                    'label' => $this->translator->trans('property_management.title'),
                    'fragment' => 'home-management',
                ],
                [
                    'path' => '/restaurants-a-essayer',
                    'label' => $this->translator->trans('restaurants.title'),
                    'fragment' => 'home-restaurants',
                ],
                [
                    'path' => '/excursions',
                    'label' => $this->translator->trans('excursions.title'),
                    'fragment' => 'home-excursions',
                ],
                [
                    'path' => '/quoi-faire-a-playa',
                    'label' => $this->translator->trans('playa_guide.title'),
                    'fragment' => 'home-playa-guide',
                ],
                [
                    'path' => '/activites-sportives',
                    'label' => $this->translator->trans('sports_activities.title'),
                    'fragment' => 'home-sports-activities',
                ],
                [
                    'path' => '/capsules-video-mexique',
                    'label' => $this->translator->trans('video_capsules.title'),
                    'fragment' => 'home-video-capsules',
                ],
                [
                    'path' => '/',
                    'label' => $this->translator->trans('playa_guide.map_title'),
                    'fragment' => 'home-map-guide',
                ],
            ],
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
