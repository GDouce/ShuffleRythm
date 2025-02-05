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
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private HttpClientInterface $httpClient;
    private SessionInterface $session;
    
    public function __construct(HttpClientInterface $httpClient, RequestStack $requestStack)
    {
        $this->clientId = $_ENV['SPOTIFY_CLIENT_ID'];
        $this->clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'];
        $this->redirectUri = $_ENV['SPOTIFY_REDIRECT_URI'];
        $this->httpClient = $httpClient;
        $this->session = $requestStack->getSession();
    }

    #[Route('/connect/spotify', name: 'connect_spotify')]
    public function connect(): Response
    {
        $url = "https://accounts.spotify.com/authorize?" . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => 'user-read-email user-library-read playlist-modify-public playlist-modify-private', 
        ]);

        return $this->redirect($url);
    }

    #[Route('/callback/spotify', name : 'spotify_callback')]
    public function callback(Request $request, HttpClientInterface $httpCLient): Response
    {
        $code = $request->query->get('code');

        if (empty($code)) {
            return new Response('Erreur : Aucun code reçu', 400);
        }

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

        $data = $response->toArray();

        if (!isset($data['access_token'])) {
            return new Response ('Erreur lors de la récupération du token', 400);
        }

        $accessToken = $data['access_token'];

        $this->setUserAccessToken($accessToken);

        

        return new Response('Connecté');
    }

    private function getUserSpotifyId(string $accessToken): ?string
    {
        $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);

        $data = $response->toArray();

        return $data['id'] ?? null;
    }

    #[Route('/create-playlist-form', name: 'create_playlist_form')]
    public function showCreatePlaylistForm(): Response
    {
        return $this->render('spotify/create_playlist.html.twig');
    }

    #[Route('/create-playlist', name: 'create_playlist', methods: ['POST'])]
public function createPlaylist(Request $request, EntityManagerInterface $entityManager): Response
{
    $accessToken = $this->getUserAccessToken();
    $userId = $this->getUserSpotifyId($accessToken);

    if (!$accessToken || !$userId) {
        return new Response('Erreur : Impossible de récupérer l\'ID de l\'utilisateur', 400);
    }

    $playlistName = trim($request->request->get('playlist_name', ''));
    $searchMode   = trim($request->request->get('search_mode', 'keyword'));
    $playlistTheme = trim($request->request->get('playlist_theme', ''));

    $trackLimit = (int) $request->request->get('track_limit', 10);
    $trackLimit = max(1, min($trackLimit, 50));

    if (empty($playlistTheme)) {
        return new Response('Erreur : Le champ de saisie est vide', 400);
    }

    $playlistData = [
        'name'        => $playlistName ?: 'Nouvelle playlist',
        'description' => "Playlist générée à partir de la recherche {$searchMode}:{$playlistTheme}",
        'public'      => true,
    ];

    $response = $this->httpClient->request('POST', "https://api.spotify.com/v1/users/$userId/playlists", [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
        ],
        'json' => $playlistData,
    ]);

    $playlist = $response->toArray();
    if (!isset($playlist['id'])) {
        return new Response('Erreur lors de la création de la playlist', 400);
    }
    $playlistId = $playlist['id'];

    $trackUris = $this->searchTracksByTheme($accessToken, $playlistTheme, $searchMode, $trackLimit);
    if (empty($trackUris)) {
        return new Response("Aucune piste trouvée pour \"{$playlistTheme}\"", 400);
    }

    $this->addTracksToPlaylist($accessToken, $playlistId, $trackUris);

    $playlist = new Playlist();
    $playlist->setName($playlistName);
    $playlist->setDescription("Playliste créée avec le thème '{$playlistTheme}");
    $playlist->setSpotifyId($playlistId);

    $entityManager->persist($playlist);
    $entityManager->flush();

    return $this->redirectToRoute('app_home');
}

private function searchTracksByTheme(string $accessToken, string $theme, string $searchMode, int $trackLimit): array
{
    $trackUris = [];

    if ($searchMode === 'artist') {
        $artists = array_map('trim', explode(',', $theme));
        $numArtists = count($artists);
        $tracksPerArtist = max(1, intval($trackLimit / $numArtists));

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

            if (!empty($data['tracks']['items'])) {
                $count = 0;
                foreach ($data['tracks']['items'] as $track) {
                    if ($count >= $tracksPerArtist) break;
                    $trackUris[] = $track['uri'];
                    $count++;
                }
            }
        }
    } else if ($searchMode === 'genre') {
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
        if (!empty($data['tracks']['items'])) {
            foreach ($data['tracks']['items'] as $track) {
                $trackUris[] = $track['uri'];
            }
        }
    } else {
        // Mode "mot-clÃ©"
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
        if (!empty($data['tracks']['items'])) {
            foreach ($data['tracks']['items'] as $track) {
                $trackUris[] = $track['uri'];
            }
        }
    }

    return $trackUris;
}


    private function addTracksToPlaylist(string $accessToken, string $playlistId, array $trackUris): void
    {
        if (empty($trackUris)) {
            return;
        }

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

    private function getUserAccessToken(): ?string
    {
        return $this->session->get('spotify_access_token');
    }

    private function setUserAccessToken(string $accessToken): void
    {
        $this->session->set('spotify_access_token', $accessToken);
    }


    #[Route('/preview-playlist', name: 'preview_playlist', methods: ['POST'])]
    public function previewPlaylist(Request $request): Response
    {
        $accessToken = $this->getUserAccessToken();

        if (!$accessToken) {
            return new Response('Erreur : Accès non autorisé', 401);
        }

        $playlistName = trim($request->request->get('playlist_name', 'Nouvelle Playlist'));
        $searchMode = trim($request->request->get('search_mode', 'keyword'));
        $playlistTheme = trim($request->request->get('playlist_theme', ''));
        $trackLimit = (int) $request->request->get('track_limit', 10);
        $trackLimit = max(1, min($trackLimit, 50));

        if (empty($playlistTheme)) {
            return new Response('Erreur : veuillez entrer un thème.', 400);
        }

        $trackUris = $this->searchTracksByTheme($accessToken, $playlistTheme, $searchMode, $trackLimit);

        if (empty($trackUris)) {
            return new Response('Aucune chanson trouvée', 400);
        }

        $trackDetails = $this->getTracksDetails($accessToken, $trackUris);

        return $this->render('spotify/preview_playlist.html.twig', [
            'playlistName' => $playlistName,
            'playlistTheme' => $playlistTheme,
            'searchMode' => $searchMode,
            'tracks' => $trackDetails,
            'trackLimit' => $trackLimit,
        ]);
    }

    private function getTracksDetails(string $accessToken, array $trackUris): array
{
    if (empty($trackUris)) {
        throw new \Exception("Erreur : Aucun track URI fourni.");
    }

    $trackIds = array_map(fn($uri) => str_replace('spotify:track:', '', $uri), $trackUris);
    $trackIdsQuery = implode(',', $trackIds);

    $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/tracks', [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
        ],
        'query' => [
            'ids' => $trackIdsQuery,
        ],
    ]);

    if ($response->getStatusCode() !== 200) {
        throw new \Exception("Erreur lors de la récupération des détails : " . $response->getStatusCode());
    }

    $data = $response->toArray();
    $tracks = [];

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

    #[Route('/historique-playlist', name: 'historique_playlists')]
    public function showPlaylistHistory(EntityManagerInterface $entityManager): Response
    {
        $playlists = $entityManager->getRepository(Playlist::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->render('spotify/historique_playlist.html.twig', [
            'playlists' =>$playlists,
        ]);
    }
}