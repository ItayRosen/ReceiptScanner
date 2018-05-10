<?php
//this is a receipt scanner API that uses Google's Vision API platform to scan receipt and export its items, total cost and date. Currently working for English based receipts only.

class parser {
	
	public $imageUri;
	public $apiKey;
	private $data;
	private $rows = [];
	private $items = [];
	private $rowHeight = 0.8; //percentage for same row height
	private $xDistance = 6; //max distance between prices in percentages
	private $maxY;
	private $maxX;
	private $total;
	private $date;
	
	public function parse() {
		$this -> getData(); //get json data
		$this -> calculateMaxY(); //calculate the most distanced element (price) on Xaxis
		$this -> parseRows(); //loop through blocks and create rows
		//print_r($this -> rows);
		$this -> calculateMaxX(); //calculate the most distanced element (price) on Xaxis
		$this -> parseItems(); //loop through rows and create items
		$this -> cleanItems(); //remove unwanted items (taxes, subtotal etc).
		$this -> calculateTotal(); //calculate the total and remove from items
		$this -> removeTotals(); //remove total items after total calculation
		$this -> setDate(); //set receipt data
		return json_encode(array("status" => 200, "data" => array("items" => $this -> items, "total" => $this -> total, "date" => $this -> date)));
	}
	
	private function getData() {
		$data = '{
		  "requests": [
			{
			  "image": {
				"source": {
				  "imageUri": "'.$this -> imageUri.'"
				}
			  },
			  "features": [
				{
				  "type": "TEXT_DETECTION"
				}
			  ]
			}
		  ]
		}';
		
		$this -> data = $this -> request($data);
	}
	
	private function request($json) {																			 
		$ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key='.$this -> apiKey);                                                                      
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));                                                                                                                   
		$result = curl_exec($ch);
		curl_close($ch);
		return $result;
	}
	
	private function calculateMaxY() {
		$max = 0;
		foreach ($this -> data -> textAnnotations as $block) {
			if (isset($block -> boundingPoly -> vertices[0] -> y) && $block -> boundingPoly -> vertices[0] -> y > $max) {
				$max = $block -> boundingPoly -> vertices[0] -> y;
			}
		}
		$this -> maxY = $max;
	}
	
	private function parseRows() {
		for ($i=1;$i<count($this -> data -> textAnnotations);$i++) {
			//check if dimensions are available
			if (!isset($this -> data -> textAnnotations[$i] -> boundingPoly -> vertices[1] -> y)) continue;
			$x = $this -> data -> textAnnotations[$i] -> boundingPoly -> vertices[1] -> x;
			$y = $this -> data -> textAnnotations[$i] -> boundingPoly -> vertices[1] -> y;
			$value = $this -> data -> textAnnotations[$i] -> description;
			//add block to existing row if found
			$closestRowIndex = $this -> searchSameRow($y);
			if ($closestRowIndex !== FALSE) {
				//check position Xaxis and add value accordingly
				if ($this -> rows[$closestRowIndex]['x'] < $x) {
					$this -> rows[$closestRowIndex]['value'] .= ' '.$value;
					$this -> rows[$closestRowIndex]['y'] = $y;
					$this -> rows[$closestRowIndex]['x'] = $x;	
				}
				else {
					$this -> rows[$closestRowIndex]['value'] = $value.' '.$this -> rows[$closestRowIndex]['value'];
				}
				//echo $closestRowIndex.' '.$value.'<br>';
			}
			else {
				$this -> rows[] = array('x' => $x, 'y' => $y, 'value' => $value);
			}
		}
		
	}
	
	private function searchSameRow($y) {
		foreach ($this -> rows as $key => $row) {
			if (abs($y - $row['y']) <= $this -> maxY / 100 * $this -> rowHeight) {
				//echo $key.' '.$y.' '.$this -> maxY.' '.($this -> maxY / 100 * $this -> rowHeight).'<br>';
				return $key;
			}
		}
		return false;
	}
	
	private function calculateMaxX() {
		$max = 0;
		foreach ($this -> rows as $row) {
			if ($row['x'] > $max) {
				$max = $row['x'];
			}
		}
		$this -> maxX = $max;
	}
	
	private function parseItems() {
		foreach ($this -> rows as $key => $row) {
			$value = $this -> stripCharacters($row['value']);
			$words = explode(' ',$value);
			$lastWord = (strlen($words[count($words)-1]) == 1 && count($words) > 1) ? $words[count($words)-2] : $words[count($words)-1]; //set last word. if it's letter, use the one before.
			//echo $value.' '.$row['x'].' '.($this -> maxX / 100 * $this -> xDistance).' '.$this -> maxX.'<br>';
			if ($this -> isPrice($lastWord) && $this -> maxX - $row['x'] <=  $this -> maxX / 100 * $this -> xDistance) {
				//if price does not contain an item name, find the nearest one
				if(!preg_match("/[a-z]/i", $value)){
					$value = $this -> nearestRow($row['y']).' '.$lastWord;
				}
				$this -> items[] = array('name' => str_replace(' '.$lastWord,'',$value), 'price' => $lastWord);
			}
		}
		if (empty($this -> items)) {
			$this -> error(401,'Could not parse receipt');
		}
	}
	
	private function nearestRow($y) {
		$max = 10000;
		foreach ($this -> rows as $row) {
			if (abs($y-$row['y']) < $max && $y != $row['y']) {
				$max = $y-$row['y'];
				$value = $row['value'];
			}
		}
		return $value;
	}
	
	private function stripCharacters($str) {
		$characters = array('%','$');
		return str_replace($characters,'',$str);
	}
	
	private function isPrice($str) {
		if (strpos($str,'.') !== FALSE && is_numeric(str_replace(array('.','$','£','€'),'',$str))) {
			return true;
		}
		return false;
	}
	
	private function cleanItems() {
		$forbiddenComplete = array('visa','tax','change due','sub total','balance','tax %','loyalty','cash','change','deficit','taxable');
		$forbiddenPart = array('subtotal','sub-total','sub total','tax ',' tax','visa ','miles','xxxxxxx');
		foreach ($this -> items as $key => $item) {
			if (in_array(strtolower($item['name']),$forbiddenComplete)) { 
				unset($this -> items[$key]);
				continue;
			}
			foreach ($forbiddenPart as $forbiddenPartItem) {
				if (strpos(strtolower($item['name']),$forbiddenPartItem) !== FALSE) {
					unset($this -> items[$key]);
					continue;
				}
			}
		}
	}
	
	private function calculateTotal() {
		$max = 0;
		$maxKey = 0;
		foreach ($this -> items as $key => $item) {
			if ($item['price'] > $max) {
				$max = $item['price'];
				$maxKey = $key;
			}
		}
		$this -> total = $max;
		unset($this -> items[$key]);
	}
	
	private function removeTotals() {
		foreach ($this -> items as $key => $item) {
			if (strtolower(str_replace(' ','',$item['name'])) == 'total' || $item['price'] == $this -> total) {
				unset($this -> items[$key]);
			}
		}
	}
	
	private function setDate() {
		foreach ($this -> rows as $row) {
			if (strpos($row['value'],'-') !== FALSE || strpos($row['value'],'/') !== FALSE) {
				$words = explode(' ',$row['value']);
				foreach ($words as $word) {
					if (is_numeric(strtotime($word))) {
						$this -> date = $word;
						break;
					}
				}
			}
		}
	}
	
	private function error($code,$text) {
		echo json_encode(array("status" => array("code" => $code, "text" => $text)));
		die;
	}
}

$parser = new parser();
$parser -> imageUri = 'https://cloud.google.com/vision/images/rushmore.jpg';
$parser -> apiKey = '';
echo $parser -> parse();
