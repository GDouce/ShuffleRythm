<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;

class ChatbotController extends AbstractController
{
    #[Route('/chatbot', name: 'chatbot', methods: ['POST'])]
    public function chatbot(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $question = strtolower(trim($data['message'] ?? ''));

        // Liste des réponses fixes
        $responses = [
            'bonjour' => 'Bonjour ! Comment puis-je vous aider ?',
            'Jai besoin daide' => 'En quoi puis-je vous aider ? (Je ne sais pas comment créer une playlist / Je suis perdu)',
            'Je suis perdu' => 'Pas de soucis, je vais vous aider. ',
            'Je ne sais pas comment créer une playlist' => 'Cest très simple, il suffit de se connecter à ton compte spotify depuis le bouton en haut à droite puis de te rendre sur la page Création e playlist',
            'au revoir' => 'Au revoir ! Passez une bonne journée.'
        ];

        // Trouver une réponse ou mettre un message par défaut
        $response = $responses[$question] ?? "Désolé, je ne comprends pas cette question.";

        return new JsonResponse(['response' => $response]);
    }
}
