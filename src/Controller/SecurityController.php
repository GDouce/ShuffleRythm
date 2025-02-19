<?php 

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    // Route pour afficher le formulaire de connexion
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Si l'utilisateur est déjà connecté, redirige vers la page d'accueil
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // Récupération des erreurs d'authentification (s'il y en a)
        $error = $authenticationUtils->getLastAuthenticationError();
        // Récupération du dernier nom d'utilisateur saisi, pour préremplir le champ dans le formulaire
        $lastUsername = $authenticationUtils->getLastUsername();

        // Retourne la vue du formulaire de connexion avec les erreurs éventuelles et le dernier nom d'utilisateur
        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername, // Prémplissage du champ nom d'utilisateur
            'error' => $error, // Affichage de l'erreur d'authentification si présente
        ]);
    }

    // Route pour déconnecter l'utilisateur
    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Cette méthode peut être vide, car Symfony gère automatiquement la déconnexion
        throw new \LogicException('This method can be blank - Symfony handles logout automatically.');
    }
}