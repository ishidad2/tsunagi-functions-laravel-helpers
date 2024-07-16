<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\SymbolHelpers;

class sendTransaction extends Command
{
    protected $signature = 'app:send-transaction {private_key} {recipient_address} {amount} {message?}';
    protected $description = 'Send a transaction using Symbol Helpers';

    public function handle()
    {
        try {
            $private_key = $this->argument('private_key');
            $recipient_address = $this->argument('recipient_address');
            $amount = $this->argument('amount');
            $message = $this->argument('message') ?? 'Transaction sent via CLI';
            $sign_secret = sodium_hex2bin($private_key);
            $sign_public = sodium_crypto_sign_publickey_from_secretkey($sign_secret);
            $signer = sodium_bin2hex($sign_public);

            $this->info('Private key: ' . substr(sodium_bin2hex($sign_secret), 0, 64));
            $this->info('Public key (hex): ' . $signer);

            $network = [
                "version" => 1,
                "network" => "TESTNET",
                "generationHash" => "49D6E1CE276A85B70EAFE52349AACCA389302E7A9754BCF1221E79494FC665A4",
                "epochAdjustment" => 1667250467,
                "catjasonBase" => "https://xembook.github.io/tsunagi-functions/catjson/0.2.0.3/",
            ];

            $deadline_time = ((time()  + 7200) - 1667250467) * 1000;

            $tx1 = [
                "type" => "TRANSFER",
                "signer_public_key" => $signer,
                "fee" => 25000,
                "deadline" => $deadline_time,
                "recipient_address" => SymbolHelpers::generateAddressId($recipient_address),
                "mosaics" => [
                    ["mosaic_id" => 0x72C0212E67A08BCE, "amount" => intval($amount)],
                ],
                "message" => $message,
            ];

            $catjson = SymbolHelpers::loadCatjson($tx1, $network);

            if (empty($catjson)) {
                $this->error("Failed to load catjson");
                return 1;
            }

            $this->info("Catjson loaded successfully");

            $layout = SymbolHelpers::loadLayout($tx1, $catjson, false);
            $prepared_tx = SymbolHelpers::prepareTransaction($tx1, $layout, $network);
            $parsed_tx = SymbolHelpers::parseTransaction($prepared_tx, $layout, $catjson, $network);

            $built_tx = SymbolHelpers::buildTransaction($parsed_tx);
            $signature = SymbolHelpers::signTransaction($built_tx, $private_key, $network);
            $built_tx = SymbolHelpers::updateTransaction($built_tx, "signature", "value", $signature);
            $tx_hash = SymbolHelpers::hashTransaction($tx1["signer_public_key"], $signature, $built_tx, $network);
            $this->info("Transaction hash: " . $tx_hash);
            $payload = SymbolHelpers::hexlifyTransaction($built_tx);
            $this->info("Payload: " . $payload);

            $node = "http://sym-test-03.opening-line.jp:3000";
            $params = json_encode(["payload" => $payload]);
            $header = ["Content-Type: application/json"];

            $context = stream_context_create([
                "http" => [
                    "method"  => 'PUT',
                    "header"  => implode("\r\n", $header),
                    "content" => $params,
                ],
            ]);

            $json_response = file_get_contents($node . "/transactions", false, $context);
            $this->info("Transaction sent. Response:");
            $this->info($json_response);

            $this->info($node . "/transactionStatus/" . $tx_hash);
            $this->info($node . "/transactions/confirmed/" . $tx_hash);
            $this->info("https://testnet.symbol.fyi/transactions/" . $tx_hash);
        } catch (\Exception $e) {
            $this->error("An error occurred: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . " Line: " . $e->getLine());
            $this->error("Stack trace:");
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}