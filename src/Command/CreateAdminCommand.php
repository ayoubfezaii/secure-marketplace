<?php

namespace App\Command;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\PasswordPolicy;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * SECURITY (rule 9): the ONLY way to create an administrator. Registration can never produce ROLE_ADMIN,
 * and there is no web page to grant it: an attacker needs shell access to the server.
 */
#[AsCommand(name: 'app:create-admin', description: 'Create an administrator account (password asked interactively, hidden).')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address of the new administrator');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));

        $emailErrors = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT), new Assert\Length(max: 180)]);
        if (count($emailErrors) > 0) {
            $io->error('Invalid email: '.$emailErrors[0]->getMessage());

            return Command::INVALID;
        }

        // SECURITY: never silently promote an existing account (it might belong to an attacker).
        if (null !== $this->users->findOneByEmail($email)) {
            $io->error('An account with this email already exists. Choose another email.');

            return Command::FAILURE;
        }

        if (!$input->isInteractive()) {
            // SECURITY: the password is never accepted as an argument/option (it would end up in shell history
            // and in the process list).
            $io->error('This command must be run interactively.');

            return Command::FAILURE;
        }

        // SECURITY: hidden input, the password is not echoed on screen.
        $password = (string) $io->askQuestion((new Question('Password'))->setHidden(true)->setHiddenFallback(false));
        $errors = $this->validator->validate($password, PasswordPolicy::constraints());
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error((string) $error->getMessage());
            }

            return Command::FAILURE;
        }

        $confirm = (string) $io->askQuestion((new Question('Repeat password'))->setHidden(true)->setHiddenFallback(false));
        if (!hash_equals($password, $confirm)) {
            $io->error('The two passwords do not match.');

            return Command::FAILURE;
        }

        $user = (new User())->setEmail($email)->setRoles([User::ROLE_ADMIN])->setIsVerified(true);
        // SECURITY: Argon2id (rule 5).
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $this->audit->log(AuditLog::ADMIN_CREATED, ['email' => $email, 'via' => 'console'], $user);
        $io->success(sprintf('Administrator %s created. Enable 2FA in "Security settings" after the first login.', $email));

        return Command::SUCCESS;
    }
}
