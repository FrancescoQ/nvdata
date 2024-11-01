<?php

namespace App\Controller;

use App\Service\AinevaDataParser;
use App\Service\AinevaRegions;
use App\Service\nvDataNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AinevaDataFromCoordinates extends AbstractController {

  #[Route('/aineva/coordinates/{x}/{y}', name: 'aineva_data_coordinates')]

  public function regionData(string $x, string $y, AinevaDataParser $parser, nvDataNormalizer $normalizer, AinevaRegions $ainevaRegions, Request $request): JsonResponse {
    // Falcade
//    $y = 46.358439;
//    $x = 11.872381;

    // Agordino / Zoldano
//    $region = 'IT-34-BL-01';

    // From the given set of coordinates we can have multiple regions.
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

    // Check if we want only a specific number of results.
    $max_results = $request->query->get('max_results');
    if ($max_results) {
      $json_data = array_slice($json_data, 0, $max_results);

      // In case we want only 1 result, return the first one and don't wrap it
      // in an array, but return the region data on its own. Likely we always
      // want only 1 region to avoid too much data to be sent to the Garmin device,
      // we leave the chance to use the entire dataset in possible other cases.
      $json_data = $max_results == 1 ? $json_data[0] : $json_data;
    }

    return $this->json($json_data);
  }
}
