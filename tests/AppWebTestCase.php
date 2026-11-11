<?php

namespace App\Tests;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for the functional security tests. The test database is loaded with the demo fixtures by
 * tests/bootstrap.php (same accounts as TESTING.md).
 */
abstract class AppWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test'], ['HTTPS' => 'on']);
        // Plain responses: we assert on status codes and redirects, not on the profiler.
        $this->client->followRedirects(false);
    }

    protected function loginAs(string $email): User
    {
        $user = $this->user($email);
        // Test login: a real security token for the "main" firewall, without going through the throttled form.
        $this->client->loginUser($user, 'main');

        return $user;
    }

    protected function user(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user, "Fixture account {$email} not found. Run the fixtures (tests/bootstrap.php).");

        return $user;
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function findOne(string $class, string $dql, array $params = []): object
    {
        $query = $this->em()->createQuery($dql)->setMaxResults(1);
        foreach ($params as $k => $v) {
            $query->setParameter($k, $v);
        }
        $result = $query->getOneOrNullResult();
        self::assertInstanceOf($class, $result, 'Fixture data not found for: '.$dql);

        return $result;
    }

    protected function assertRedirectsToLogin(): void
    {
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
