<?php

namespace App\Http\Controllers;

use App\Gateway\EventLogGateway;
use App\Gateway\QuestionGateway;
use App\Gateway\UserGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Logger;
use LINE\LINEBot;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;

class Webhook extends Controller
{
    /**
     *  @var LINEBot
     */
    private $bot;
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


        $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
        $this->bot  = new LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
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

    private function followCallback($evt)
    {
        $res = $this->bot->getProfile($evt['source']['userId']);
        if ($res->isSucceeded()) {
            $profile = $res->getJSONDecodedBody();

            $message = "Salam Kenal, " . $profile['displayName'] . "!\n";
            $message .= "Selamat Datang di Line Bot Tebak Gambar \n";
            $message .= "Kamu bisa memilih Level Tebak Gambar yang ada di Bot ini \n";
            $message .= "Saat ini Level yang tersedia adalah 1 - 2";
            $message .= "Untuk Memulai Kamu bisa Menggunakan Perintah \"/start level 1\" untuk memulai Bot";

            $textMessageBuilder = new TextMessageBuilder($message);

            $message2 = "Game ini juga ditunjukan sebagai penghibur di kala pandemi ini.";
            $message2 .= "di bot ini juga kalian bisa mengecek Statistik Virus Covid-19 di Indonesia, dengan query";
            $message2 .= "1. \"/coronastats\" untuk melihat data penyebaran Covid-19 di Indonesia";
            $message2 .= "2. \"/coronastats provinsi\" untuk melihat data penyebaran di wilayah provinsi";
            $message2 .= "3. \"/coronastats provinsi kota\" untuk melihat data penyebaran di wilayah kota";

            $textMessageBuilder2 = new TextMessageBuilder($message2);

            $message3 = "Selamat Bersenang senang";

            $textMessageBuilder3 = new TextMessageBuilder($message3);

            $stickerMessageBuilder = new StickerMessageBuilder(1, 3);

            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder);
            $multiMessageBuilder->add($textMessageBuilder2);
            $multiMessageBuilder->add($textMessageBuilder3);
            $multiMessageBuilder->add($stickerMessageBuilder);

            $this->bot->replyMessage($evt['replyToken'], $multiMessageBuilder);

            $this->userGateway->saveUser(
                $profile['userId'],
                $profile['displayName']
            );
        }
    }
}
