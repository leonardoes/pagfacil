<?php
class PagarMe_TransactionCommon extends PagarMe_Model 
{
	protected $id, $amount, $card_number, $card_holder_name, $card_expiration_month, $card_expiration_year, $card_cvv, $card_hash, $postback_url, $payment_method, $status, $date_created;
	protected $name, $document_number, $document_type, $email, $sex, $born_at, $customer; 
	protected $street, $city, $state, $neighborhood, $zipcode, $complementary, $street_number, $country;
	protected $type, $ddi, $ddd, $number, $phone_id;
	protected $resfuse_reason, $antifraud_score, $boleto_url, $boleto_barcode;
	protected $card_brand;
	protected $metadata;

	protected function generateCardHash() 
	{
		$request = new PagarMe_Request('/transactions/card_hash_key','GET');
		$response = $request->run();
		$key = openssl_get_publickey($response['public_key']);
		$params = $this->cardDataParameters();
		$str = "";
		foreach($params as $k => $v) {
			$str .= $k . "=" . $v . "&";	
		}
		$str = substr($str, 0, -1);
		openssl_public_encrypt($str,$encrypt, $key);
		return $response['id'].'_'.base64_encode($encrypt);
	}


	protected function validateCreditCard($s) {
		if(0==$s) { 
			return(false); 
		} // Don’t allow all zeros
		
		$sum=0;
		$i=strlen($s); // Find the last character
		$o=$i%2; // Shift odd/even for odd-length $s
		while ($i– > 0) { // Iterate all digits backwards
			$sum+=$s[$i]; // Add the current digit
			// If the digit is even, add it again. Adjust for digits 10+ by subtracting 9.
			($o==($i%2)) ? ($s[$i] > 4) ? ($sum+=($s[$i]-9)) : ($sum+=$s[$i]) : false;
		}
		return (0==($sum%10)) ;
	}

	//TODO Validate address and phone info
	protected function errorInTransaction() 
	{
		if($this->payment_method == 'credit_card') { 
			if(strlen($this->card_number) < 16 || strlen($this->card_number) > 20 || !$this->validateCreditCard($this->card_number)) {
				return new PagarMe_Error(array('message' => "Número de cartão inválido.", 'parameter_name' => 'card_number', 'type' => "invalid_parameter"));
			}

			else if(strlen($this->card_holder_name) == 0) {
				return new PagarMe_Error(array('message' => " Nome do portador do cartão inválido", 'parameter_name' => 'card_holder_name', 'type' => "invalid_parameter"));
			}

			else if($this->card_expiration_month <= 0 || $this->card_expiration_month > 12) {
				return new PagarMe_Error(array('message' => "Mês de expiração do cartão inválido", 'parameter_name' => 'card_expiration_date', 'type' => "invalid_parameter"));
			}

			else if($this->card_expiration_year <= 0) {
				return new PagarMe_Error(array('message' => "Ano de expiração do cartão inválido", 'parameter_name' => 'card_expiration_date', 'type' => "invalid_parameter"));
			}

			else if($this->card_expiration_year < substr(date('Y'),-2)) {
				return new PagarMe_Error(array('message' => "Cartão expirado", 'parameter_name' => 'card_expiration_date', 'type' => "invalid_parameter"));
			}

			else if(strlen($this->card_cvv) < 3  || strlen($this->card_cvv) > 4) {
				return new PagarMe_Error(array('message' => "Código de segurança inválido", 'parameter_name' => 'card_cvv', 'type' => "invalid_parameter"));
			}

			else {
				return null;
			}
		}
		if($this->amount <= 0) {
			return new PagarMe_Error(array('message' => "Valor inválido", 'parameter_name' => 'amount', 'type' => "invalid_parameter"));
		}

		if(checkCustomerInformation()) {
			if(!$this->zipcode || !$this->street_number || !$this->ddd || !$this->number || !$this->name || !$this->document_number || !$this->email || !$this->sex || !$this->born_at || !$this->street || !$this->neighborhood) {
				return new PagarMe_Error(array('message' => "Faltam informações do cliente", 'parameter_name' => 'customer', 'type' => "invalid_parameter"));
			}
		}

		return null;
	}

	protected function checkCustomerInformation() {
		if($this->zipcode || $this->complementary || $this->street_number || $this->ddd || $this->number || $this->name || $this->document_number || $this->email || $this->sex || $this->born_at || $this->street || $this->neighborhood) {
			return true;
		} else {
			return false;
		}

	}

	protected function mergeCustomerInformation($transactionInfo) {
		$transactionInfo['customer']['phone']['ddd'] = $this->ddd;
		$transactionInfo['customer']['phone']['number'] = $this->number;
		$transactionInfo['customer']['address']['street_number'] = $this->street_number;
		$transactionInfo['customer']['address']['street'] = $this->street;
		$transactionInfo['customer']['address']['neighborhood'] = $this->neighborhood;
		$transactionInfo['customer']['address']['zipcode'] = $this->zipcode;
		$transactionInfo['customer']['address']['complementary'] = $this->complementary;
		$transactionInfo['customer']['document_number'] = $this->document_number;
		$transactionInfo['customer']['email'] = $this->email;
		$transactionInfo['customer']['sex'] = $this->sex;
		$transactionInfo['customer']['born_at'] = $this->born_at;
		$transactionInfo['customer']['name'] = $this->name;
		return $transactionInfo;
	}

	protected function updateFieldsFromResponse($first_parameter)  
	{

		if($first_parameter['amount']) {
			$this->setAmount($first_parameter['amount']);
		}

		$this->status = $first_parameter['status'] ? $first_parameter['status'] : 'local';
		$this->setCustomer($first_parameter['customer']);
		if($first_parameter['payment_method'] != 'boleto') { 
			if(!$first_parameter['card_hash']) { 
				$this->card_number = (isset($first_parameter["card_number"])) ? $first_parameter['card_number']  : null;
				$this->card_holder_name = (isset($first_parameter["card_holder_name"])) ? $first_parameter['card_holder_name'] : '';
				$this->card_expiration_month = isset($first_parameter["card_expiration_month"]) ? $first_parameter['card_expiration_month'] : '';
				$this->card_expiration_year = isset($first_parameter["card_expiration_year"]) ? $first_parameter['card_expiration_year'] : '';
				if(strlen($this->card_expiration_year) >= '4') {
					$this->card_expiration_year = $this->card_expiration_year[2] . $this->card_expiration_year[3];
				}
				$this->card_cvv = isset($first_parameter["card_cvv"]) ? $first_parameter['card_cvv'] : '';
				$this->postback_url = isset($first_parameter['postback_url']) ? $first_parameter['postback_url'] : '';
			} elseif(isset($first_parameter['card_hash'])) {
				$this->card_hash = $first_parameter['card_hash'];
			}
		}

		$this->installments = isset($first_parameter['installments']) ? $first_parameter["installments"] : '';
		$this->payment_method = isset($first_parameter['payment_method']) ? $first_parameter['payment_method'] : 'credit_card';
		$this->refuse_reason = isset($first_parameter['refuse_reason']) ? $first_parameter['refuse_reason'] : '';
		$this->street = isset($first_parameter['customer']['address']['street']) ? $first_parameter['customer']['address']['street'] : 0;
		$this->city = isset($first_parameter['customer']['address']['city']) ? $first_parameter['customer']['address']['city'] : '';
		$this->state = isset($first_parameter['customer']['address']['state']) ? $first_parameter['customer']['address']['state'] : '';
		$this->neighborhood = isset($first_parameter['customer']['address']['neighborhood']) ? $first_parameter['customer']['address']['neighborhood'] : '';
		$this->zipcode = isset($first_parameter['customer']['address']['zipcode']) ? $first_parameter['customer']['address']['zipcode'] : '';
		$this->complementary = isset($first_parameter['customer']['address']['complementary']) ? $first_parameter['customer']['address']['complementary'] : '';
		$this->street_number = isset($first_parameter['customer']['address']['street_number']) ? $first_parameter['customer']['address']['street_number'] : '';
		$this->country = isset($first_parameter['customer']['address']['country']) ? $first_parameter['customer']['address']['country'] : '';
		$this->type = isset($first_parameter['customer']['phone']['type']) ? $first_parameter['customer']['phone']['type'] : '';
		$this->ddi = isset($first_parameter['customer']['phone']['ddi']) ? $first_parameter['customer']['phone']['ddi'] : '';
		$this->ddd = isset($first_parameter['customer']['phone']['ddd']) ? $first_parameter['customer']['phone']['ddd'] : '';
		$this->number = isset($first_parameter['customer']['phone']['number']) ? $first_parameter['customer']['phone']['number'] : '';
		$this->id = isset($first_parameter['id']) ? $first_parameter['id'] : '';
		$this->name = isset($first_parameter['customer']['name']) ? $first_parameter['customer']['name'] : '';
		$this->document_type = isset($first_parameter['customer']['document_type']) ? $first_parameter['customer']['document_type'] : '';
		$this->document_number = isset($first_parameter['customer']['document_number']) ? $first_parameter['customer']['document_number'] : '';
		$this->email = isset($first_parameter['customer']['email']) ? $first_parameter['customer']['email'] : '';
		$this->born_at = isset($first_parameter['customer']['born_at']) ? $first_parameter['customer']['born_at'] : '';
		$this->sex = isset($first_parameter['customer']['sex']) ? $first_parameter['customer']['sex'] : '';
		$this->card_brand = isset($first_parameter['card_brand']) ? $first_parameter['card_brand'] : '';
		$this->boleto_url = isset($first_parameter['boleto_url']) ? $first_parameter['boleto_url'] : '';
		$this->metadata = isset($first_parameter['metadata']) ? $first_parameter['metadata'] : '';
	}

	protected function cardDataParameters() 
	{
		return array(
			"card_number" => $this->card_number,
			"card_holder_name" => $this->card_holder_name,
			"card_expiration_date" => $this->card_expiration_month . $this->card_expiration_year,
			"card_cvv" => $this->card_cvv
		);
	}

	function setAmount($amount) { 
		if($amount) {
			$amount = str_ireplace(',', "", $amount);
			$amount = str_ireplace('.', "", $amount);
			$amount = str_ireplace('R$', "", $amount);		   
			$amount = trim($amount);
			$this->amount = $amount;
		}	

	}

	function getAmount() { return $this->amount; }
	function setCardNumber($card_number) { $this->card_number = $card_number; }
	function getCardNumber() { return $this->card_number; }
	function setCardHolderName($card_holder_name) { $this->card_holder_name = $card_holder_name; }
	function getCardHolderName() { return $this->card_holder_name; }
	function setCardExpirationMonth($card_expiration_month) { $this->card_expiration_month = $card_expiration_month; }
	function getCardExpirationMonth() { return $this->card_expiration_month; }
	function setCardExpirationYear($card_expiration_year) { $this->card_expiration_year = $card_expiration_year; }
	function getCardExpirationYear() { return $this->card_expiration_year; }
	function setCardCvv($card_cvv) { $this->card_cvv = $card_cvv; }
	function getCardCvv() { return $this->card_cvv; }
	function setLive($live) { $this->live = $live; }
	function getLive() { return $this->live; }
	function setCardHash($card_hash) { $this->card_hash = $card_hash; }
	function getCardHash() { return $this->card_hash; }
	function setInstallments($installments) { $this->installments = $installments; }
	function getInstallments() { return $this->installments; }
	function getStatus() { return $this->status; }
	function setStatus($status) { $this->status = $status;}
	function setPaymentMethod($payment_method) {$this->payment_method = $payment_method;}
	function getPaymentMethod(){return $this->payment_method;}
	function setDateCreated($date_created) { $this->date_created = $date_created;}
	function getDateCreated() { return $this->date_created;}
	function getId() { return $this->id; }
	function setId($id) {$this->id = $id;}
	function getCardBrand() { return $this->card_brand; }
	function setCardBrand($card_brand) {$this->card_brand = $card_brand;}
	function getMetadata() { return $this->metadata;}
	function setMetadata($metadata) { $this->metadata = $metadata; } 

	//Address Info
	public function getStreet() { return $this->street;}
	public function setStreet($street) {$this->street = $street;}

	public function getCity() { return $this->city;}

	public function getState() { return $this->state;}

	public function getNeighborhood() { return $this->neighborhood;}
	public function setNeighborhood($neighborhood) { $this->neighborhood = $neighborhood;}

	public function setZipcode($zipcode) {$this->zipcode = $zipcode;}
	public function getZipcode() { return $this->zipcode;}

	public function getAddressId() { return $this->address_id;}

	public function setComplementary($complementary) {$this->complementary = $complementary;}
	public function getComplementary() { return $this->complementary;}

	public function setStreetNumber($street_number) {$this->street_number = $street_number;}
	public function getStreetNumber() { return $this->street_number;}

	public function getCountry() { return $this->country;}

	// Phone Info

	function getPhoneType() {return $this->type;}

	public function getDDI() {return $this->ddi;}

	public function setDDD($ddd) {$this->ddd = $ddd;}
	public function getDDD() {return $this->ddd;}

	public function setNumber($number) {$this->number = $number;}
	public function getNumber() {return $this->number;}

	public function getPhoneId() {return $this->phone_id;}


	//Customer info

	public function getName() { return $this->name;}
	public function setName($name) { $this->name = $name; }

	public function getDocumentNumber() { return $this->document_number;}
	public function setDocumentNumber($document_number) { $this->document_number = $document_number; }

	public function getDocumentType() { return $this->document_type;}
	public function setDocumentType($document_type) { $this->document_type = $document_type; }

	public function getEmail() { return $this->email;}
	public function setEmail($email) { $this->email = $email; }

	public function getSex() { return $this->sex;}
	public function setSex($sex) { $this->sex = $sex; }

	public function setCustomer($customer) {
		if($customer) { 
			$this->customer = new PagarMe_Customer($customer);
		}
	}

	public function getCustomer() {
		return $this->customer;
	}


	public function getBornAt() { return $this->born_at;}
	public function setBornAt($born_at) { $this->born_at = $born_at; }

	public function getRefuseReason() { return $this->refuse_reason;}

	public function getAntifraudeScore() { return $this->antifraud_score;}

	public function getBoletoUrl() { return $this->boleto_url;}

	public function getBoletoBarcode() { return $this->boleto_barcode;}
} 

?>
