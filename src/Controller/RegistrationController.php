<?php 

namespace App\Controller;

use App\Entity\Users;
use App\Form\RegistrationFormType;
use App\Security\CustomLoginAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{
    // Route pour afficher le formulaire d'inscription
    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, Security $security, EntityManagerInterface $entityManager): Response
    {
        // Création d'une nouvelle instance de l'entité Users pour l'enregistrement
        $user = new Users();
        
        // Création du formulaire à partir de la classe de formulaire RegistrationFormType
        $form = $this->createForm(RegistrationFormType::class, $user);
        
        // Traitement de la requête pour vérifier si le formulaire a été soumis
        $form->handleRequest($request);

        // Si le formulaire est soumis et valide
        if ($form->isSubmitted() && $form->isValid()) {
            // Récupération du mot de passe en clair (avant hachage)
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // Hachage du mot de passe et attribution à l'utilisateur
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            // Persistance de l'entité utilisateur dans la base de données
            $entityManager->persist($user);
            $entityManager->flush();

            // Redirection vers la page de connexion après l'inscription
            return $this->redirectToRoute('app_login');
        }

        // Affichage du formulaire d'inscription dans la vue
        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form, // Passe le formulaire à la vue
        ]);
    }
}
