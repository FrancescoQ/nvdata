<?php

namespace App\Controller;

use App\Service\AinevaDataParser;
use App\Service\AinevaRegions;
use App\Service\nvDataNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class AinevaDataNavigation extends AbstractController {

  /**
   * @var \App\Service\AinevaDataParser
   */
  private $parser;

  public function __construct(AinevaDataParser $parser) {
    $this->parser = $parser;
  }

  #[Route('/aineva-navigation', name: 'aineva_navigation')]
  public function regionsNavigation(): JsonResponse {
    $navigation = $this->parser->getRegionsTree();
    return $this->json($navigation);
  }
}
