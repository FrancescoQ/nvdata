<?php

namespace App\Controller;

use App\Service\ArpavDataParser;
use App\Service\nvDataNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ArpavData extends AbstractController {

  #[Route('/arpav', name: 'arpav_data')]
  public function arpav(ArpavDataParser $parser): JsonResponse {
    $data = $parser->getData();
    return $this->json($data);
  }
}
