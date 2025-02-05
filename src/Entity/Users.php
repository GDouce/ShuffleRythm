<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity]
class Users implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', unique: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'string')]
    private ?string $password = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    // Cette méthode est obligatoire mais peut être vide si vous n'avez pas de données sensibles à effacer
    public function eraseCredentials(): void
    {
        // Exemple : Si vous stockez un mot de passe en clair temporairement, nettoyez-le ici
        // $this->plainPassword = null;
    }

    // Remplace l'ancienne méthode getUsername() et retourne l'identifiant de l'utilisateur
    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    // Getter pour le champ username (facultatif si vous utilisez getUserIdentifier)
    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getRoles(): array
    {
        // Retourne un tableau vide si vous ne gérez pas de rôles
        return [];
    }
}