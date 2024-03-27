<?php

namespace App\Controller;

use App\Service\AinevaDataParser;
use App\Service\nvDataNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AinevaData extends AbstractController {

  #[Route('/aineva/{region}', name: 'aineva_data')]
  public function regionData(string $region, AinevaDataParser $parser, LoggerInterface $logger): JsonResponse {
    try {
      $data = $parser->getRegionData($region);
    }
    catch (\Exception $e) {
      $logger->error($e->getMessage());
      $data = [];
    }
    return $this->json($data);
  }
}
