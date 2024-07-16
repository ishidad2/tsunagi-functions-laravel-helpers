<?php

namespace App\Helpers;

use Base32\Base32;

class SymbolHelpers
{
    public static function loadCatjson($tx, $network)
    {
        $jsonFile = '';
        if ($tx["type"] === "AGGREGATE_COMPLETE" || $tx["type"] === "AGGREGATE_BONDED") {
            $jsonFile = "aggregate.json";
        } else {
            $jsonFile = strtolower($tx["type"]) . ".json";
        }

        $res = file_get_contents($network["catjasonBase"] . $jsonFile);
        return json_decode($res, true);
    }

    public static function loadLayout($tx, $catjson, $isEmbedded)
    {
        $prefix = $isEmbedded ? "Embedded" : "";

        $layoutName = '';
        if ($tx["type"] === "AGGREGATE_COMPLETE") {
            $layoutName = "AggregateCompleteTransactionV2";
        } elseif ($tx["type"] === "AGGREGATE_BONDED") {
            $layoutName = "AggregateBondedTransactionV2";
        } else {
            $layoutName = $prefix . self::toCamelCase(strtolower($tx["type"])) . "TransactionV1";
        }

        $conditions = ["prefix" => $prefix, "layout_name" => $layoutName];
        $factory = array_filter($catjson, function ($item) use ($conditions) {
            return isset($item['factory_type']) && $item['factory_type'] == $conditions["prefix"] . "Transaction" && $item["name"] === $conditions["layout_name"];
        });

        return array_values($factory)[0]["layout"];
    }

    public static function toCamelCase($str)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $str)));
    }

    public static function prepareTransaction($tx, $layout, $network)
    {
        $preparedTx = $tx;
        $preparedTx["network"] = $network["network"];
        $preparedTx['version'] = ($tx["type"] === "AGGREGATE_COMPLETE" || $tx["type"] === "AGGREGATE_BONDED") ? 2 : 1;

        if (isset($preparedTx['message'])) {
            $preparedTx['message'] = "00" . bin2hex($tx['message']);
        }

        if (isset($preparedTx['name'])) {
            $preparedTx['name'] = bin2hex($tx['name']);
        }

        if (isset($preparedTx['value'])) {
            $preparedTx['value'] = bin2hex($tx['value']);
        }

        if (isset($tx['mosaics'])) {
            $ids = array_column($preparedTx['mosaics'], 'mosaic_id');
            array_multisort($ids, SORT_ASC, $preparedTx['mosaics']);
        }

        foreach ($layout as $layer) {
            if (isset($layer["size"]) && !is_numeric($layer["size"])) {
                $size = 0;

                if (isset($layer["element_disposition"]) && isset($preparedTx[$layer["name"]])) {
                    $size = strlen($preparedTx[$layer["name"]]) / ($layer["element_disposition"]["size"] * 2);
                } elseif (strpos($layer["size"], '_count') !== false) {
                    $size = isset($preparedTx[$layer["name"]]) ? count($preparedTx[$layer["name"]]) : 0;
                }

                $preparedTx[$layer["size"]] = $size;
            }
        }

        if (isset($tx["transactions"])) {
            $txes = [];
            foreach ($tx["transactions"] as $eTx) {
                $eCatjson = self::loadCatjson($eTx, $network);
                $eLayout = self::loadLayout($eTx, $eCatjson, true);
                $ePreparedTx = self::prepareTransaction($eTx, $eLayout, $network);
                array_push($txes, $ePreparedTx);
            }
            $preparedTx["transactions"] = $txes;
        }

        return $preparedTx;
    }

    //トランザクション解析
		public static function parseTransaction($tx,$layout,$catjson,$network) {

			$parsed_tx = []; //return
			foreach($layout as $layer){

				$layer_type = $layer["type"];
				$layer_disposition = "";
				if(isset($layer["disposition"])){
					$layer_disposition = $layer["disposition"];
				}
				$filter_item = array_filter($catjson, function($cj) use($layer_type){
					return $cj["name"] === $layer_type;
				});
				$catitem = array_values($filter_item);

				if(count($catitem) > 0 ){
					$catitem = $catitem[0];
				}

				if(isset($layer["condition"])){
					if($layer["condition_operation"] === "equals"){
						if($layer["condition_value"] !== $tx[$layer["condition"]]){

							continue;
						}
					}
				}

				if($layer_disposition === "const"){
					continue;

				}else if($layer_type === "EmbeddedTransaction"){

					$tx_layer = $layer;
					$items = [];
					foreach($tx["transactions"] as $e_tx){ //小文字のeはembeddedの略
						$e_catjson = self::loadCatjson($e_tx,$network);//catjsonの更新
						$e_layout = self::loadLayout($e_tx,$e_catjson,true); //isEmbedded:true

						$e_parsed_tx = self::parseTransaction($e_tx,$e_layout,$e_catjson,$network); //再帰
						array_push($items,$e_parsed_tx);
					}
					$tx_layer["layout"] = $items;
					array_push($parsed_tx,$tx_layer);
					continue;

				}else if(isset($catitem["layout"]) && isset($tx[$layer["name"]]) ){

					$tx_layer = $layer;
					$items = [];
					foreach($tx[$layer["name"]] as $item){

						$filter_value = array_filter($catjson, function($cj) use($layer_type){
							return $cj["name"] === $layer_type;
						});
						$filter_layer = array_values($filter_value)[0];

						$item_parsed_tx = self::parseTransaction($item,$filter_layer["layout"],$catjson,$network); //再帰
						array_push($items,$item_parsed_tx);
					}
					$tx_layer["layout"] = $items;
					array_push($parsed_tx,$tx_layer);
					continue;

				}else if($layer_type === "UnresolvedAddress"){

					//アドレスに30個の0が続く場合はネームスペースとみなします。
					if(isset($tx[$layer["name"]]) && !is_array($tx[$layer["name"]]) && strpos($tx[$layer["name"]],'000000000000000000000000000000') !== false){

						$filter_value = array_filter($catjson, function($cj){
							return $cj["name"] === "NetworkType";
						});

						$network_type = array_values($filter_value)[0];
		//				print_r($network_type);
		//				print_r($tx["network"]);

						$filter_network = array_filter($network_type["values"], function($cj) use($tx){
							return $cj["name"] === $tx["network"];
						});
						$network_value = array_values($filter_network)[0]["value"];

						$prefix = dechex($network_value + 1);
						$tx[$layer["name"]] =  $prefix . $tx[$layer["name"]];
					}
				}else if(isset($catitem["type"]) && $catitem["type"] === "enum"){

					if(strpos($catitem["name"],'Flags') !== false){

						$value = 0;
						foreach($catitem["values"] as $item_layer){

							if(strpos($tx[$layer["name"]],$item_layer["name"]) !== false){

								$value += $item_layer["value"];
							}
						}
						$catitem["value"] = $value;

					}else if(strpos($layer_disposition,'array') !== false ){

						$values = [];
						foreach($tx[$layer["name"]] as $item){

							$filter_value = array_filter($catitem["values"], function($cj) use($item){
								return $cj["name"] === $item;
							});

							$item_value = array_values($filter_value)[0]["value"];
							array_push($values,$item_value);
						}
						$tx[$layer["name"]] = $values;
					}else{

						//NetworkType
						$conditions = ["tx" => $tx,"layer_name" => $layer["name"] ];
						$filter_value = array_filter($catitem["values"], function($cj) use($conditions){

							return $cj["name"] === $conditions["tx"][$conditions["layer_name"]];
						});
						$catitem["value"] = array_values($filter_value)[0]["value"];
					}
				}

				//layerの配置
				if(strpos($layer_disposition,'array') !== false ){

					if($layer_type === "byte"){

						$size = 0;
						if(isset($tx[$layer["size"]])){
							$size = $tx[$layer["size"]];
						}

						if(isset($layer["element_disposition"])){ //message

							$sub_layout = $layer;
							$items = [];
							for($count = 0; $count < $size; $count++){
								$tx_layer = [];
								$tx_layer["signedness"] = $layer["element_disposition"]["signedness"];
								$tx_layer["name"] = "element_disposition";
								$tx_layer["size"] = $layer["element_disposition"]["size"];
								$tx_layer["value"] = substr($tx[$layer["name"]],$count * 2, 2);
								$tx_layer["type"] = $layer_type;
								array_push($items,$tx_layer);
							}
							$sub_layout["layout"] = $items;
							array_push($parsed_tx, $sub_layout);

						}else{print_r("not yet");}
					}else if(isset($tx[$layer["name"]])){

						$sub_layout = $layer;
						$items = [];
						foreach($tx[$layer["name"]] as $tx_item){

							$filter_layer = array_filter($catjson, function($cj) use($layer_type){
								return $cj["name"] === $layer_type;
							});
							$tx_layer = array_values($filter_layer)[0];
							$tx_layer["value"] = $tx_item;
							if($layer_type === "UnresolvedAddress"){
								//アドレスに30個の0が続く場合はネームスペースとみなします。
								if(strpos($tx_item,'000000000000000000000000000000') !== false){

									$filter_value = array_filter($catjson, function($cj){
										return $cj["name"] === "NetworkType";
									});
									$network_type = array_values($filter_value)[0];

									$filter_network = array_filter($network_type["values"], function($cj) use($tx){
										return $cj["name"] === $tx["network"];
									});

									$network_value = array_values($filter_network)[0]["value"];

									$prefix = dechex($network_value + 1);
									$tx_layer["value"] =  $prefix . $tx_layer["value"];

								}
							}
							array_push($items,$tx_layer);
						}
						$sub_layout["layout"] = $items;
						array_push($parsed_tx,$sub_layout);

					}// else{console.log("not yet");}
				}else{ //reserved またはそれ以外(定義なし)

					$tx_layer = $layer;
					if(count($catitem) > 0){

						//catjsonのデータを使う
						if(isset($catitem["signedness"])){
							$tx_layer["signedness"]	= $catitem["signedness"];
						}
						if(isset($catitem["size"])){
							$tx_layer["size"]  = $catitem["size"];

						}
						if(isset($catitem["type"])){
							$tx_layer["type"]  = $catitem["type"];

						}
						if(isset($catitem["value"])){
							$tx_layer["value"] = $catitem["value"];
						}
					}

					//txに指定されている場合上書き(enumパラメータは上書きしない)
					if(isset($layer["name"]) && isset($tx[$layer["name"]]) ){
						if(isset($catitem["type"]) && $catitem["type"] === "enum"){

						}else{
							$tx_layer["value"] = $tx[$layer["name"]];
						}
					}else{

					}
		//			print_r("push tx_layer".PHP_EOL);
		//			print_r($tx_layer);
					array_push($parsed_tx,$tx_layer);
				}
			}

			$layer_size = array_filter($parsed_tx, function($pf){
				return $pf["name"] === "size";
			} );

			if(isset($layer_size[0]["size"])){

		//		print_r($parsed_tx);
				$parsed_tx[array_keys($layer_size)[0]]["value"] = self::countSize($parsed_tx);
			}
			return $parsed_tx;
		}

		//サイズ計算
		public static function countSize($item,$alignment = 0) {
			$total_size = 0;
			//レイアウトサイズの取得
			if(isset($item)  && isset($item["layout"])){
				foreach( $item["layout"] as $layer){
					$item_alignment;
					if(isset($item["alignment"])){
						$item_alignment = $item["alignment"];
					}else{
						$item_alignment = 0;
					}
					$total_size += self::countSize($layer,$item_alignment); //再帰
				}
			//レイアウトを構成するレイヤーサイズの取得
			}else if(array_values($item) === $item){

				$layout_size = 0;
				foreach($item as $key => $value){

					$layout_size += self::countSize($item[$key],$alignment);//再帰
				}

				if(isset($alignment)  && $alignment > 0){
					$layout_size = floor(($layout_size  + $alignment - 1) / $alignment ) * $alignment;
				}
				$total_size += $layout_size;
			}else{

				if(isset($item["size"])){

					$total_size += $item["size"];
				}else{
					print_r("no size:" + $item["name"]);
				}
			}

			return $total_size;
		}

		//トランザクション構築
		public static function buildTransaction($parsed_tx) {

			$built_tx = $parsed_tx;

			$layer_payload_size = array_filter($built_tx, function($bf){
				return $bf["name"] === "payload_size";
			});

			if(count($layer_payload_size) > 0 ){

				$filter_transactions =  array_filter($built_tx, function($bf){
					return $bf["name"] === "transactions";
				});
				$transactions = array_values($filter_transactions)[0];
				$built_tx[array_keys($layer_payload_size)[0]]["value"] = self::countSize($transactions);
			}

			//Merkle Hash Builder
			$layer_transactions_hash =  array_filter($built_tx, function($bf){
				return $bf["name"] === "transactions_hash";
			});

			if(count($layer_transactions_hash) > 0){

				$hashes = [];
				$filter_transactions =  array_filter($built_tx, function($bf){
					return $bf["name"] === "transactions";
				});

				$transactions = array_values($filter_transactions)[0];
				foreach($transactions["layout"] as $e_tx){


					$digest = hash('sha3-256',
						sodium_hex2bin(
							self::hexlifyTransaction($e_tx)
						)
					);
					array_push($hashes,$digest);
				}

				$num_remaining_hashes = count($hashes);
				while (1 < $num_remaining_hashes) {

					$i = 0;
					while ($i < $num_remaining_hashes) {
						$hasher = hash_init('sha3-256');
						hash_update($hasher,sodium_hex2bin($hashes[$i]));

						if ($i + 1 < $num_remaining_hashes) {
							hash_update($hasher,sodium_hex2bin($hashes[$i+1]));
						} else {
							// if there is an odd number of hashes, duplicate the last one
							hash_update($hasher,sodium_hex2bin($hashes[$i]));
							$num_remaining_hashes += 1;
						}
						$hashes[intval($i / 2)] = hash_final($hasher,false);
						$i += 2;
					}
					$num_remaining_hashes = intval($num_remaining_hashes / 2);

				}
				$built_tx[array_keys($layer_transactions_hash)[0]]["value"] = $hashes[0];
			}

			return $built_tx;
		}

		//トランザクションを16進数でシリアライズ
		public static function hexlifyTransaction($item,$alignment = 0) {

			$hex = "";
			if(isset($item["layout"])){
				foreach($item["layout"] as $layer){
					$item_alignment;
					if(isset($item["alignment"])){
						$item_alignment = $item["alignment"];
					}else{
						$item_alignment = 0;
					}
					$hex .= self::hexlifyTransaction($layer,$item_alignment); //再帰
				}
			}else if(array_values($item) === $item){

				$sub_layout_hex = "";
				foreach($item as $sub_layout){
					$sub_layout_hex .= self::hexlifyTransaction($sub_layout,$alignment);//再帰
				}

				if(isset($alignment) && $alignment > 0){
					$aligned_size = floor(( strlen($sub_layout_hex) + ($alignment * 2) - 2)/ ($alignment * 2) ) * ($alignment * 2);
					$sub_layout_hex = $sub_layout_hex . str_repeat ("0",$aligned_size - strlen($sub_layout_hex));
				}
				$hex .= $sub_layout_hex;
			}else{
				$size = $item["size"];
				if(!isset($item["value"])){
					if($size >= 24){
						$item["value"] = str_repeat("00",$size);
					}else{
						$item["value"] = 0;
					}
				}

				if($size==1){
					if($item["name"] === "element_disposition"){
						$hex = $item["value"];
					}else{
						$hex = bin2hex(pack('C', $item["value"]));
					}
				}else if($size==2){
					$hex = bin2hex(pack('v', $item["value"]));
				}else if($size==4){
					$hex = bin2hex(pack('V', $item["value"]));
				}else if($size==8){

					//0xffffffffffffffff を 00000000000000000としてしまう現象回避
					if(sprintf('%016X',$item["value"]) == "00000000000000000" && $item["value"] > 0){

						$hex = "ffffffffffffffff";

					}else{
						$hex = bin2hex(pack('P', $item["value"]));
					}
				}else if($size==24 || $size==32 || $size==64){
					$hex = $item["value"];
				}else{
					print_r("unknown size order");
				}
			}
			return $hex;
		}

		//トランザクション署名
		public static function signTransaction($built_tx,$private_key,$network) {

			$sign_secret = sodium_hex2bin($private_key);
			$verifiable_data = self::getVerifiableData($built_tx);

			$payload = $network["generationHash"] . self::hexlifyTransaction($verifiable_data);
			$signature = sodium_bin2hex(sodium_crypto_sign_detached(sodium_hex2bin($payload), $sign_secret));

			return $signature; 
		}

		//検証データ取得
		public static function getVerifiableData($built_tx) {

			$filter_layer = array_filter($built_tx,function($fb){
				return $fb["name"] === "type";
			});
			$type_layer = array_values($filter_layer)[0];

			if(in_array($type_layer["value"], [16705,16961])){
				return array_slice($built_tx,5,6);
			}else{
				return array_slice($built_tx,5);
			}
		}

		//トランザクションのハッシュ値計算
		public static function hashTransaction($signer,$signature,$built_tx,$network) {

			$hasher = hash_init('sha3-256');
			hash_update($hasher,sodium_hex2bin($signature));
			hash_update($hasher,sodium_hex2bin($signer));
			hash_update($hasher,sodium_hex2bin($network["generationHash"]));
			hash_update($hasher,sodium_hex2bin(self::hexlifyTransaction(self::getVerifiableData($built_tx))));

			$tx_hash = hash_final($hasher,false);

			return $tx_hash;
		}

		//トランザクション更新
		public static function updateTransaction($built_tx,$name,$type,$value) {

			$layer = array_filter($built_tx,function($fb) use($name){
				return $fb["name"] === $name;
			});

			$built_tx[array_keys($layer)[0]][$type] = $value;
			return $built_tx;
		}


		//連署
		public static function cosignTransaction($tx_hash,$private_key) {

			$sign_secret = sodium_hex2bin($private_key);
			$signature = sodium_bin2hex(sodium_crypto_sign_detached(sodium_hex2bin($tx_hash), $sign_secret));

			return $signature;
		}


    public static function generateAddressId($address)
    {
        return bin2hex(Base32::decode($address));
    }

    public static function generateNamespaceId($name, $parentNamespaceId = 0)
    {
        $NAMESPACE_FLAG = 1 << 63;

        $hasher = hash_init('sha3-256');
        hash_update($hasher, pack('V', $parentNamespaceId & 0xFFFFFFFF));
        hash_update($hasher, pack('V', ($parentNamespaceId >> 32) & 0xFFFFFFFF));
        hash_update($hasher, $name);

        $digest = unpack("C*", hex2bin(hash_final($hasher, false)));
        $result = self::digestToBigint($digest);

        return $result | $NAMESPACE_FLAG;
    }

    public static function generateKey($name)
    {
        $NAMESPACE_FLAG = 1 << 63;

        $hasher = hash_init('sha3-256');
        hash_update($hasher, $name);

        $digest = unpack("C*", hex2bin(hash_final($hasher, false)));
        $result = self::digestToBigint($digest);

        return $result | $NAMESPACE_FLAG;
    }

    public static function generateMosaicId($ownerAddress, $nonce)
    {
        $NAMESPACE_FLAG = 1 << 63;

        $hasher = hash_init('sha3-256');
        hash_update($hasher, pack('V', $nonce));
        hash_update($hasher, hex2bin($ownerAddress));
        $digest = unpack("C*", hex2bin(hash_final($hasher, false)));
        $result = self::digestToBigint($digest);

        if ($result & $NAMESPACE_FLAG) {
            $result -= $NAMESPACE_FLAG;
        }

        return $result;
    }

    public static function convertAddressAliasId($namespaceId)
    {
        return bin2hex(pack('P', $namespaceId)) . "000000000000000000000000000000";
    }

    private static function digestToBigint($digest)
    {
        $result = 0;
        for ($i = 0; $i < 8; $i++) {
            $result += $digest[$i + 1] << 8 * $i;
        }
        return $result;
    }
}