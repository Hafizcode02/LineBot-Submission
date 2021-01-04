<?php

namespace App\Gateway;

use Illuminate\Database\ConnectionInterface;

class QuestionGateway
{
    /**
     * @var ConnectionInterface
     */
    private $db;

    public function __construct()
    {
        $this->db = app('db');
    }

    public function getQuestion(int $questionNum, string $level)
    {
        $question = $this->db->table('questions')
            ->where('number', $questionNum)
            ->where('level', $level)
            ->first();

        if ($question) {
            return (array) $question;
        }

        return null;
    }

    function isAnswerEqual(int $number, string $answer, string $level)
    {
        return $this->db->table('questions')
            ->where('number', $number)
            ->where('level', $level)
            ->where('answer', $answer)
            ->exists();
    }
}
