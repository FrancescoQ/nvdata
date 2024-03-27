<?php

namespace App\Controller;

use App\Service\AinevaDataParser;
use App\Service\AinevaRegions;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Index extends AbstractController {

  /**
   * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
   */
  private $router;

  /**
   * @var \App\Service\AinevaDataParser
   */
  private $ainevaParser;

  /**
   * @var \App\Service\AinevaRegions
   */
  private $ainevaRegions;

  function __construct(UrlGeneratorInterface $router, AinevaRegions $ainevaRegions, AinevaDataParser $ainevaDataParser) {
    $this->router = $router;
    $this->ainevaParser = $ainevaDataParser;
    $this->ainevaRegions = $ainevaRegions;
  }

  #[Route('/', name: 'nvdata_index')]
  public function index(): Response {

    $links = [
      'ARPAV' => [
        'ARPAV' => $this->router->generate('arpav_data')
      ],
    ];

    $regions = $this->ainevaRegions->getRegions();
    $bulletins = $this->ainevaParser->getBulletins();
    foreach ($regions as $region_id => $region_name) {
      if (empty($bulletins[$region_id])) {
        continue;
      }

      $links['AINEVA'][$region_name] = $this->router->generate('aineva_data', ['region' => $region_id]);
    }

    $tree = $this->ainevaRegions->getRegionTree();

    return $this->render('index.html.twig', ['links' => $links]);
  }
}
