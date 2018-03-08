<?php

namespace App\Manager;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class MriqManager
{
    /**
     * @var SlackManager
     */
    private $slackManager;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var int
     */
    private $maxTransactionAmount;

    /**
     * MriqManager constructor.
     * @param SlackManager $slackManager
     */
    public function __construct(SlackManager $slackManager, EntityManagerInterface $em, int $maxTransactionAmount)
    {
        $this->slackManager = $slackManager;
        $this->em = $em;
        $this->maxTransactionAmount = $maxTransactionAmount;
    }

    /**
     * Updates the list of users in database
     */
    public function updateUsersList() : array
    {
        $response = json_decode($this->slackManager->getSlackUsersList()->getBody()->getContents(), true);
        $rawUsers = $response['members'];
        $results = [
            'added' => [],
            'known' => []
        ];

        foreach ($rawUsers as $rawUser) {
            if ($rawUser['is_bot'] ||
                $rawUser['deleted'] ||
                $rawUser['is_ultra_restricted'] ||
                $rawUser['is_restricted'] ||
                $rawUser['name'] == 'slackbot'
            ) {
                continue;
            } else {
                $users[] = $rawUser['name'];
                $existingUser = $this->em->getRepository(User::class)
                    ->findUserBySlackId($rawUser['id']);

                if (null !== $existingUser) {
                    $user = $existingUser;
                    $results['known'][] = $rawUser['name'];
                } else {
                    $user = new User();
                    $results['added'][] = $rawUser['name'];
                }

                $user
                    ->setSlackId($rawUser['id'])
                    ->setSlackName($rawUser['name'])
                    ->setSlackRealName($rawUser['profile']['real_name_normalized']);

                $this->em->persist($user);
            }
        }
        $this->em->flush();

        return $results;
    }

    /**
     * @param User $giver
     * @param User $receiver
     * @param int $amount
     * @param string $reason
     * @return array
     * @throws \Exception
     */
    public function treatMriqs(User $giver, User $receiver, int $amount, string $reason)
    {
        //Check if user is trying to give briqs to himself
        if ($giver->getSlackId() == $receiver->getSlackId()) {
            throw new \Exception('Did you really think it was this easy ? Come on 😜 !');
        }

        //Check if transaction exceeds the limit
        if ($amount > $this->maxTransactionAmount) {
            $errorString = sprintf(
                'Go easy there, treats cannot exceed %s mriqs at the moment 💰',
                $this->maxTransactionAmount
            );
            throw new \Exception($errorString);
        }

        if ($amount == 0) {
            $errorString = sprintf(
                'Come on, don\'t be greedy, you have %s mriqs to give, go spread some love ❤️ !',
                $giver->getToGive()
            );
            throw new \Exception($errorString);
        }

        //Check if user has enough briqs to give
        if ($giver->getToGive() >= $amount) {
            $gaveLastMriqs = $giver->getToGive() == $amount;

            $transaction = (new Transaction())
                ->setReason($reason)
                ->setAmount($amount)
                ->setGiver($giver)
                ->setReceiver($receiver);

            $receiver->receiveBriqs($amount);
            $giver->giveBriqs($amount);

            $this->em->persist($receiver);
            $this->em->persist($giver);
            $this->em->persist($transaction);

            $this->em->flush();

            return array(
                'success' => true,
                'last' => $gaveLastMriqs
            );
        } else {
            throw new \Exception(
                'Whooooops, you don\'t have enough mriqs to be this generous at the moment 💸, sorry 😢.'
            );
        }
    }
}
