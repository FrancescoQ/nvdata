<?php

namespace App\Controller;

use App\Service\AinevaDataParser;
use App\Service\nvDataNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AinevaData extends AbstractController {

  #[Route('/aineva/{region}', name: 'aineva_data')]
  public function regionData(string $region, AinevaDataParser $parser, nvDataNormalizer $normalizer): JsonResponse {
    $data = $parser->getRegionData($region);
    return $this->json($data);
  }
}
