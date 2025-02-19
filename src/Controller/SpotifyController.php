<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Entity\Playlist;
use Doctrine\ORM\EntityManagerInterface;

final class SpotifyController extends AbstractController
{
    // Déclaration des variables nécessaires pour l'authentification et l'accès à l'API Spotify
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private HttpClientInterface $httpClient;
    private SessionInterface $session;
    
    // Constructeur permettant d'initialiser les paramètres de l'API Spotify et d'obtenir la session
    public function __construct(HttpClientInterface $httpClient, RequestStack $requestStack)
    {
        $this->clientId = $_ENV['SPOTIFY_CLIENT_ID'];
        $this->clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'];
        $this->redirectUri = $_ENV['SPOTIFY_REDIRECT_URI'];
        $this->httpClient = $httpClient;
        $this->session = $requestStack->getSession();
    }

    // Route pour lancer l'autorisation via Spotify OAuth
    #[Route('/connect/spotify', name: 'connect_spotify')]
    public function connect(): Response
    {
        // Construction de l'URL de redirection pour l'autorisation Spotify
        $url = "https://accounts.spotify.com/authorize?" . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => 'user-read-email user-library-read playlist-modify-public playlist-modify-private', 
        ]);

        // Redirection de l'utilisateur vers Spotify pour l'autorisation
        return $this->redirect($url);
    }

    // Callback après l'autorisation de l'utilisateur pour récupérer le token d'accès
    #[Route('/callback/spotify', name : 'spotify_callback')]
    public function callback(Request $request, HttpClientInterface $httpCLient): Response
    {
        // Récupération du code d'autorisation depuis l'URL
        $code = $request->query->get('code');

        // Vérification de la présence du code d'autorisation
        if (empty($code)) {
            return new Response('Erreur : Aucun code reçu', 400);
        }

        // Envoi de la requête pour récupérer le token d'accès avec le code d'autorisation
        $response = $this->httpClient->request('POST', 'https://accounts.spotify.com/api/token', [
            'headers' => [ 
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ],
        ]);

        // Récupération du token d'accès depuis la réponse
        $data = $response->toArray();

        // Vérification de la présence du token d'accès
        if (!isset($data['access_token'])) {
            return new Response ('Erreur lors de la récupération du token', 400);
        }

        $accessToken = $data['access_token'];

        // Sauvegarde du token d'accès dans la session
        $this->setUserAccessToken($accessToken);

        // Redirection vers la page d'accueil après la connexion réussie
        return $this->redirectToRoute('app_home');
    }

    // Fonction pour récupérer l'ID de l'utilisateur connecté à Spotify
    private function getUserSpotifyId(string $accessToken): ?string
    {
        $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);

        // Récupération des données de l'utilisateur
        $data = $response->toArray();

        // Retourne l'ID de l'utilisateur ou null si l'ID est introuvable
        return $data['id'] ?? null;
    }

    // Route pour afficher le formulaire de création de playlist
    #[Route('/create-playlist-form', name: 'create_playlist_form')]
    public function showCreatePlaylistForm(): Response
    {
        // Affichage du formulaire de création de playlist
        return $this->render('spotify/create_playlist.html.twig');
    }

    // Route pour créer une playlist via l'API Spotify
    #[Route('/create-playlist', name: 'create_playlist', methods: ['POST'])]
    public function createPlaylist(Request $request, EntityManagerInterface $entityManager): Response
    {
        // Récupération du token d'accès et de l'ID utilisateur
        $accessToken = $this->getUserAccessToken();
        $userId = $this->getUserSpotifyId($accessToken);

        // Vérification de la validité du token et de l'ID utilisateur
        if (!$accessToken || !$userId) {
            return new Response('Erreur : Impossible de récupérer l\'ID de l\'utilisateur', 400);
        }

        // Récupération des données du formulaire
        $playlistName = trim($request->request->get('playlist_name', ''));
        $searchMode   = trim($request->request->get('search_mode', 'keyword'));
        $playlistTheme = trim($request->request->get('playlist_theme', ''));

        // Limitation du nombre de pistes (entre 1 et 50)
        $trackLimit = (int) $request->request->get('track_limit', 10);
        $trackLimit = max(1, min($trackLimit, 50));

        // Vérification si le champ thème est vide
        if (empty($playlistTheme)) {
            return new Response('Erreur : Le champ de saisie est vide', 400);
        }

        // Préparation des données pour créer la playlist
        $playlistData = [
            'name'        => $playlistName ?: 'Nouvelle playlist',
            'description' => "Playlist générée à partir de la recherche {$searchMode}:{$playlistTheme}",
            'public'      => true,
        ];

        // Requête pour créer la playlist sur Spotify
        $response = $this->httpClient->request('POST', "https://api.spotify.com/v1/users/$userId/playlists", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => $playlistData,
        ]);

        // Récupération de l'ID de la playlist créée
        $playlist = $response->toArray();
        if (!isset($playlist['id'])) {
            return new Response('Erreur lors de la création de la playlist', 400);
        }
        $playlistId = $playlist['id'];

        // Recherche des pistes par thème
        $trackUris = $this->searchTracksByTheme($accessToken, $playlistTheme, $searchMode, $trackLimit);
        if (empty($trackUris)) {
            return new Response("Aucune piste trouvée pour \"{$playlistTheme}\"", 400);
        }

        // Ajout des pistes à la playlist
        $this->addTracksToPlaylist($accessToken, $playlistId, $trackUris);

        // Création de l'entité Playlist dans la base de données
        $playlist = new Playlist();
        $playlist->setName($playlistName);
        $playlist->setDescription("Playliste créée avec le thème '{$playlistTheme}'");
        $playlist->setSpotifyId($playlistId);

        // Sauvegarde de la playlist dans la base de données
        $entityManager->persist($playlist);
        $entityManager->flush();

        // Redirection vers la page d'accueil après la création de la playlist
        return $this->redirectToRoute('app_home');
    }

// Fonction pour rechercher des morceaux par thème (artist, genre, ou mot-clé)
    private function searchTracksByTheme(string $accessToken, string $theme, string $searchMode, int $trackLimit): array
    {
        // Tableau pour stocker les URIs des morceaux trouvés
        $trackUris = [];

        // Recherche par artiste
        if ($searchMode === 'artist') {
            // Découper les artistes par virgule et les nettoyer
            $artists = array_map('trim', explode(',', $theme));
            $numArtists = count($artists);
            // Calcul du nombre de morceaux à rechercher par artiste
            $tracksPerArtist = max(1, intval($trackLimit / $numArtists));

            // Pour chaque artiste, effectuer une recherche de morceaux
            foreach ($artists as $artist) {
                $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/search', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'query' => [
                        'q'    => 'artist:"' . $artist . '"',
                        'type' => 'track',
                        'limit' => $tracksPerArtist,
                    ],
                ]);

                $data = $response->toArray();

                // Si des morceaux sont trouvés, les ajouter à la liste
                if (!empty($data['tracks']['items'])) {
                    $count = 0;
                    foreach ($data['tracks']['items'] as $track) {
                        if ($count >= $tracksPerArtist) break;
                        $trackUris[] = $track['uri'];
                        $count++;
                    }
                }
            }
        } 
        // Recherche par genre
        else if ($searchMode === 'genre') {
            $queryString = 'genre:"' . trim($theme) . '"';

            $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/search', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'q'    => $queryString,
                    'type' => 'track',
                    'limit' => $trackLimit,
                ],
            ]);

        $data = $response->toArray();

            // Si des morceaux sont trouvés, les ajouter à la liste
            if (!empty($data['tracks']['items'])) {
                foreach ($data['tracks']['items'] as $track) {
                    $trackUris[] = $track['uri'];
                }
            }
        } 
        // Recherche par mot-clé
        else {
            $queryString = trim($theme);
            $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/search', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'q'    => $queryString,
                    'type' => 'track',
                    'limit' => $trackLimit,
                ],
            ]);

            $data = $response->toArray();

            // Si des morceaux sont trouvés, les ajouter à la liste
            if (!empty($data['tracks']['items'])) {
                foreach ($data['tracks']['items'] as $track) {
                    $trackUris[] = $track['uri'];
                }
            }
        }

        return $trackUris;
    }

    // Fonction pour ajouter des morceaux à une playlist
    private function addTracksToPlaylist(string $accessToken, string $playlistId, array $trackUris): void
    {
        // Si aucune URI de morceau n'est donnée, ne rien faire
        if (empty($trackUris)) {
            return;
        }

        // Ajouter les morceaux à la playlist via l'API Spotify
        $this->httpClient->request('POST', "https://api.spotify.com/v1/playlists/$playlistId/tracks", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'uris' => $trackUris,
            ],
        ]);
    }

    // Récupérer le token d'accès de l'utilisateur depuis la session
        private function getUserAccessToken(): ?string
    {
        return $this->session->get('spotify_access_token');
    }

    // Sauvegarder le token d'accès de l'utilisateur dans la session
    private function setUserAccessToken(string $accessToken): void
    {
        $this->session->set('spotify_access_token', $accessToken);
    }

    // Route pour prévisualiser la playlist (création et affichage)
    #[Route('/preview-playlist', name: 'preview_playlist', methods: ['POST'])]
    public function previewPlaylist(Request $request): Response
    {
        // Récupérer le token d'accès de l'utilisateur
        $accessToken = $this->getUserAccessToken();

       // Vérifier si l'utilisateur est autorisé
       if (!$accessToken) {
           return new Response('Erreur : Accès non autorisé', 401);
       }

       // Récupérer les informations de la requête
       $playlistName = trim($request->request->get('playlist_name', 'Nouvelle Playlist'));
       $searchMode = trim($request->request->get('search_mode', 'keyword'));
       $playlistTheme = trim($request->request->get('playlist_theme', ''));
       $trackLimit = (int) $request->request->get('track_limit', 10);
       $trackLimit = max(1, min($trackLimit, 50));

       // Vérifier si le thème de la playlist est vide
       if (empty($playlistTheme)) {
           return new Response('Erreur : veuillez entrer un thème.', 400);
       }

       // Rechercher les morceaux en fonction du thème et du mode de recherche
       $trackUris = $this->searchTracksByTheme($accessToken, $playlistTheme, $searchMode, $trackLimit);

       // Vérifier si des morceaux ont été trouvés
       if (empty($trackUris)) {
          return new Response('Aucune chanson trouvée', 400);
      }

      // Récupérer les détails des morceaux trouvés
      $trackDetails = $this->getTracksDetails($accessToken, $trackUris);

      // Afficher la page de prévisualisation de la playlist
      return $this->render('spotify/preview_playlist.html.twig', [
          'playlistName' => $playlistName,
          'playlistTheme' => $playlistTheme,
          'searchMode' => $searchMode,
          'tracks' => $trackDetails,
          'trackLimit' => $trackLimit,
      ]);
    }

    // Fonction pour récupérer les détails des morceaux en utilisant leurs URIs
    private function getTracksDetails(string $accessToken, array $trackUris): array
    {
      // Vérifier si la liste d'URIs est vide
      if (empty($trackUris)) {
          throw new \Exception("Erreur : Aucun track URI fourni.");
      }

      // Extraire les IDs des morceaux depuis les URIs
     $trackIds = array_map(fn($uri) => str_replace('spotify:track:', '', $uri), $trackUris);
     $trackIdsQuery = implode(',', $trackIds);

      // Requête à l'API Spotify pour récupérer les détails des morceaux
     $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/tracks', [
         'headers' => [
              'Authorization' => 'Bearer ' . $accessToken,
           ],
           'query' => [
              'ids' => $trackIdsQuery,
           ],
      ]);

     // Vérifier si la requête a réussi
      if ($response->getStatusCode() !== 200) {
          throw new \Exception("Erreur lors de la récupération des détails : " . $response->getStatusCode());
      }

      $data = $response->toArray();
     $tracks = [];

     // Si des morceaux sont trouvés, les ajouter à la liste des détails
     if (!empty($data['tracks'])) {
         foreach ($data['tracks'] as $track) {
                $tracks[] = [
                    'id' => $track['id'], 
                 'name' => $track['name'],
                 'artist' => implode(', ', array_map(fn($artist) => $artist['name'], $track['artists'])),
                 'album' => $track['album']['name'],
                 'cover' => $track['album']['images'][0]['url'] ?? null,
             ];
         }
     }

     return $tracks;
    }

    // Route pour afficher l'historique des playlists
    #[Route('/historique-playlist', name: 'historique_playlists')]
    public function showPlaylistHistory(EntityManagerInterface $entityManager): Response
    {
       // Récupérer les playlists de l'historique
       $playlists = $entityManager->getRepository(Playlist::class)->findBy([], ['createdAt' => 'DESC']);

       // Afficher la page avec l'historique des playlists
       return $this->render('spotify/historique_playlist.html.twig', [
           'playlists' =>$playlists,
       ]);
    }
}