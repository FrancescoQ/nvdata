<?php

namespace App\Controller;

use App\Service\AinevaDataParser;
use App\Service\AinevaRegions;
use App\Service\nvDataNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class AinevaDataFromCoordinates extends AbstractController {

  #[Route('/aineva/coordinates/{x}/{y}', name: 'aineva_data_coordinates')]

  public function regionData(string $x, string $y, AinevaDataParser $parser, nvDataNormalizer $normalizer, AinevaRegions $ainevaRegions): JsonResponse {
    // Falcade
//    $y = 46.358439;
//    $x = 11.872381;

    // Agordino / Zoldano
//    $region = 'IT-34-BL-01';

    $regions = $ainevaRegions->getCoordinatesMicroRegions($x, $y);
    if (!$regions) {
      return $this->json([]);
    }

    $json_data = [];

    foreach ($regions as $region) {
      $data = $parser->getRegionData($region);
      if (!$data) {
        continue;
      }
      $json_data[] = $data;
    }
    return $this->json($json_data);
  }
}
