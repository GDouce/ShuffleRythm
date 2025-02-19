<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    // Route pour la page d'accueil
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Retourne la réponse en rendant le template 'home/index.html.twig' avec un paramètre 'controller_name'
        return $this->render('home/index.html.twig', [
            'controller_name' => 'HomeController', // Paramètre envoyé à la vue (pour l'affichage du nom du contrôleur)
        ]);
    }
}
