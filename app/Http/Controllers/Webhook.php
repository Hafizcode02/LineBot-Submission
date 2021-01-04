<?php

namespace App\Http\Controllers;

use App\Gateway\EventLogGateway;
use App\Gateway\QuestionGateway;
use App\Gateway\UserGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Logger;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\RawMessageBuilder;
use LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;

class Webhook extends Controller
{
    /**
     *  @var LINEBot
     */
    private $bot;
    /**
     * @var HTTPClient
     */
    private $httpClient;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Response
     */
    private $response;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var EventLogGateway
     */
    private $logGateway;
    /**
     * @var UserGateway
     */
    private $userGateway;
    /**
     * @var QuestionGateway
     */
    private $questionGateway;
    /**
     * @var array
     */
    private $user;

    public function __construct(
        Request $request,
        Response $response,
        Logger $logger,
        EventLogGateway $logGateway,
        UserGateway $userGateway,
        QuestionGateway $questionGateway
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;
        $this->logGateway = $logGateway;
        $this->userGateway = $userGateway;
        $this->questionGateway = $questionGateway;


        $this->httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
        $this->bot  = new LINEBot($this->httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
    }

    public function __invoke()
    {
        $body = $this->request->all();

        $this->logger->debug('Body', $body);

        $signature = $this->request->server('HTTP_X_LINE_SIGNATURE') ?: '-';
        $this->logGateway->saveLog($signature, json_encode($body, true));

        return $this->handleEvents();
    }

    private function handleEvents()
    {
        $data = $this->request->all();

        if (is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                if (!isset($event['source']['userId'])) continue;

                $this->user = $this->userGateway->getUser($event['source']['userId']);

                if (!$this->user) $this->followCallback($event);
                else {
                    if ($event['type'] == 'message') {
                        if (method_exists($this, $event['message']['type'] . 'Message')) {
                            $this->{$event['message']['type'] . 'Message'}($event);
                        }
                    } else {
                        if (method_exists($this, $event['type'] . 'Callback')) {
                            $this->{$event['message']['type'] . 'Callback'}($event);
                        }
                    }
                }
            }
        }

        $this->response->setContent("No Events Found!");
        $this->response->setStatusCode(200);
        return $this->response;
    }

    private function followCallback($event)
    {
        $res = $this->bot->getProfile($event['source']['userId']);
        if ($res->isSucceeded()) {
            $profile = $res->getJSONDecodedBody();

            $message = "Salam Kenal, " . $profile['displayName'] . "!\n";
            $message .= "Selamat Datang di Line Bot Tebak Gambar, ";
            $message .= "Kamu bisa memilih Level Tebak Gambar yang ada di Bot ini. \n";
            $message .= "List Perintah : \n";
            $message .= "1. \"/start level 1\"\n";
            $message .= "2. \"/start level 2\"";

            $textMessageBuilder = new TextMessageBuilder($message);

            $message2 = "Selamat Bersenang senang";

            $textMessageBuilder2 = new TextMessageBuilder($message2);

            $stickerMessageBuilder = new StickerMessageBuilder(1, 114);

            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder);
            $multiMessageBuilder->add($textMessageBuilder2);
            $multiMessageBuilder->add($stickerMessageBuilder);

            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);

            $this->userGateway->saveUser(
                $profile['userId'],
                $profile['displayName']
            );
        }
    }

    private function textMessage($event)
    {
        $userMessage = $event['message']['text'];
        $uID = $event['source']['userId'];
        if ($this->user['number'] == 0) {
            if (strtolower($userMessage) == '/start level 1') {
                $this->userGateway->setUserProgress($this->user['user_id'], 1);
                $this->userGateway->setLevel($this->user['user_id'], 1);
                $this->sendQuestion($event['replyToken'], 1, 1);
            } else if (strtolower($userMessage) == '/start level 2') {
                $this->userGateway->setUserProgress($this->user['user_id'], 1);
                $this->userGateway->setLevel($this->user['user_id'], 2);
                $this->sendQuestion($event['replyToken'], 1, 2);
            } else {
                $message = "silahkan kirimkan perintah dibawah untuk memulai: \n";
                $message .= "1. \"/start level 1\"\n";
                $message .= "2. \"/start level 2\"";
                $textMessageBuilder = new TextMessageBuilder($message);
                $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
            }
        } else {
            $data = $this->userGateway->getUser($uID);
            $this->checkAnswer($userMessage, $event['replyToken'], $data['level']);
        }
    }

    private function stickerMessage($event)
    {
        $stickerMessageBuilder = new StickerMessageBuilder(1, 114);
        $message = "silahkan kirimkan perintah dibawah untuk memulai: \n";
        $message .= "1. \"/start level 1\"\n";
        $message .= "2. \"/start level 2\"";
        $textMessageBuilder = new TextMessageBuilder($message);

        $multiMessageBuilder = new MultiMessageBuilder();
        $multiMessageBuilder->add($stickerMessageBuilder);
        $multiMessageBuilder->add($textMessageBuilder);

        $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
    }

    private function sendQuestion($replyToken, $questionNum = 1, $level = 1)
    {
        $question = $this->questionGateway->getQuestion($questionNum, $level);

        $flex_tmp = file_get_contents(url('/template/flex.json'));
        $parse = json_decode($flex_tmp, true);
        $parse['hero']['url'] = $question['image'];
        $parse['body']['contents'][0]['text'] = $question['number'] . "\n";
        $parse['body']['contents'][1]['text'] = $question['text'];
        $convertedJson = json_encode($parse);

        if ($questionNum > 1) {
            return $flexMessageBuilder = new RawMessageBuilder([
                'type' => 'flex',
                'altText' => 'Soal Flex Message',
                'contents' => json_decode($convertedJson)
            ]);
        } else {
            $this->httpClient->post(LINEBot::DEFAULT_ENDPOINT_BASE . '/v2/bot/message/reply', [
                'replyToken' => $replyToken,
                'messages' => [
                    [
                        'type' => 'flex',
                        'altText' => 'Test Flex Message',
                        'contents' => json_decode($convertedJson)
                    ]
                ],
            ]);
        }
    }

    private function checkAnswer(string $message, $replyToken, string $level)
    {
        if ($this->questionGateway->isAnswerEqual($this->user['number'], strtolower($message), $level)) {
            $messageTrue = "Benar, Jawabannya adalah : " . ucwords($message);
            $textMessageBuilderTrue = new TextMessageBuilder($messageTrue);

            if ($this->user['number'] < 6) {
                $this->userGateway->setUserProgress($this->user['user_id'], $this->user['number'] + 1);
                $send = $this->sendQuestion($replyToken, $this->user['number'] + 1, $level);

                $multiMessageBuilder = new MultiMessageBuilder();
                $multiMessageBuilder->add($textMessageBuilderTrue);
                $multiMessageBuilder->add($send);

                $this->bot->replyMessage($replyToken, $multiMessageBuilder);
            } else {
                $message = "Selamat Kamu telah menyelesaikan Level " . $this->user['level'];
                $textMessageBuilderFinish = new TextMessageBuilder($message);

                $stickerMessageBuilderFinish = new StickerMessageBuilder(1, 13);

                $messages = "Ayo Mulai Lagi dengan level berikutnya,\n";
                $messages .= "silahkan kirimkan perintah dibawah untuk memulai: \n";
                $messages .= "1. \"/start level 1\"\n";
                $messages .= "2. \"/start level 2\"";
                $textmessagebuilders = new TextMessageBuilder($messages);

                $multiMessageBuilder = new MultiMessageBuilder();
                $multiMessageBuilder->add($textMessageBuilderTrue);
                $multiMessageBuilder->add($textMessageBuilderFinish);
                $multiMessageBuilder->add($stickerMessageBuilderFinish);
                $multiMessageBuilder->add($textmessagebuilders);

                $this->bot->replyMessage($replyToken, $multiMessageBuilder);
                $this->userGateway->setUserProgress($this->user['user_id'], 0);
                $this->userGateway->setLevel($this->user['user_id'], 0);
            }
        } else {
            $message = "Jawaban Kamu Salah, Coba Lagi.";
            $textMessageBuilder = new TextMessageBuilder($message);
            $sendAgain = $this->sendQuestion($replyToken, $this->user['number'], $level);

            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder);
            $multiMessageBuilder->add($sendAgain);

            $this->bot->replyMessage($replyToken, $multiMessageBuilder);
        }
    }
}
