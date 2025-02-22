<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;

class ChatbotController extends AbstractController
{
    #[Route('/chatbot/ask', name: 'app_chatbot_ask', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        $message = $request->request->get('message');

        // Tableau des questions/réponses
        $qa = [
            'Bonjour' => 'Bonjour ! Comment puis-je vous aider ? (Je suis perdu / Je ne comprends pas comment créer une playlist)',
            'Salut' => 'Bonjour ! Comment puis-je vous aider ? (Je suis perdu / Je ne comprends pas comment créer une playlist)',
            'Je suis perdu' => 'Pas de panique. Vous pouvez créer une playlist depuis longlet Créer une playlist, mais pour se faire vous devez être connecté à Spotify via le bouton en haut à droite de la page. Cela à-t-il répondu à votre question ?',
            'Je ne comprends pas comment créer une playlist' => 'Je comprends, je vais vous expliquer simplement. Il vous suffit de cliquer sur la page Créer une playlist et tout est inscrit sur la page de création de la playlist. Cela à-t-il répondu à votre question ?',
            'Oui, merci' => 'Pas de soucis, je suis là pour ça !',
            'Oui' => 'Pas de soucis, je suis là pour ça !',
            'Oui merci' => 'Pas de soucis, je suis là pour ça !',
        ];

        // Réponse associée ou message par défaut
        $answer = $qa[$message] ?? "Désolé, je n'ai pas compris votre question. Veuillez suivre les réponses prédéfinies";

        return new JsonResponse(['answer' => $answer]);
    }
}
