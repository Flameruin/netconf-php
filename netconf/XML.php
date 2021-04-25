<?php
include('XMLBuilder.php');

/**
 * An <code>XML</code> object represents XML content and provides methods to
 * manipulate it.
 * The <code>XML</code> object has an 'active' element, which represents the
 * hierarchy at which the XML can be manipulated.
 * <p>
 * As an @example, one
 * <ol>
 * <li>creates a XMLBuilder object.</li>
 * <li>create a configuration as an XML object.</li>
 * <li>Call the loadXMLConfiguration(XML) method on Device</li>
 * </ol>
 */
class XML{
	var $active_element;
	var $owner_doc;
	var $simplexml;

	/**
	 * XML constructor.
	 * @param        $active       - initlize active_element
	 * @param        $document     - initlize owner_doc
	 * @param string $original_xml - used to make simplexml (ns is the namespace for xpath)
	 */
	public function __construct($active, $document, $original_xml = ''){

		$this->active_element = $active;
		$this->owner_doc      = $document;
		//if the original wasn't sent make one
		if($original_xml === ''){
			$original_xml = $this->toString();
		}
		$first_xmlns_start  = strpos($original_xml, 'xmlns="') + strlen('xmlns="');
		$second_xmlns_start = strpos($original_xml, 'xmlns="', $first_xmlns_start) + strlen('xmlns="');
		//if no second xmlns use the first one
		$xmlns_start = ($second_xmlns_start !== false) ? $second_xmlns_start : $first_xmlns_start;
		$xmlns_end   = strpos($original_xml, '"', $xmlns_start + strlen('xmlns="'));
		//get namespace to register to xpath
		$ns              = substr($original_xml, $xmlns_start, $xmlns_end - $xmlns_start);
		$this->simplexml = simplexml_load_string($original_xml);
		$this->simplexml->registerXPathNamespace('ns', $ns);//$this->simplexml->xpath('//ns:<tag>')
	}

	/**
	 * Get the owner Document for the XML object.
	 */
	public function get_owner_document(){

		return $this->owner_doc;
	}

	/**
	 * Get the owner Documentcontent for the XML object.
	 */
	public function get_owner_document_content(){

		return $this->owner_doc->textContent;
	}

	/**
	 * Get the active element for the XML object.
	 */
	public function get_active_element(){

		return $this->active_element;
	}

	/**
	 * Get the active element content for the XML object.
	 */
	public function get_active_element_content(){

		return $this->active_element->textContent;
	}

	/**
	 * Get the active element node name for the XML object.
	 */
	public function get_active_element_node_name(){

		return $this->active_element->nodeName;
	}

	/**
	 * Get the active element node value for the XML object.
	 */
	public function get_active_element_node_value(){

		return $this->active_element->nodeValue;
	}

	/**
	 * Set atirbute for the active element of XML object.
	 * @param $name  - The name of the attribute.
	 * @param $value - The value of the attribute.
	 */
	public function set_attribute($name, $value){

		$this->active_element->set_attribute($name, $value);
	}

	/**
	 * Set text for the active elment of XML object.
	 * @param $text - The text value to be set.
	 */
	public function set_text($text){

		$firstChild = $this->active_element->firstChild;
		if($firstChild == null){
			$textNode = $this->owner_doc->createTextNode($text);
			$this->active_element->appendChild($textNode);
		}else{
			if($firstChild->nodeType != 3){
				$textNode = $this->domDocument->createNextNode($text);
				$this->active_element->appendChild($textNode);
			}else{
				$firstChild->nodeValue = $text;
			}
		}
	}

	/**
	 * Sets the attribute ("delete","delete") for the active element of
	 * XML object.
	 */
	public function junos_delete(){

		$this->active_element->set_attribute("delete", "delete");
	}

	/**
	 * Sets the attribute ("active","active") for the active element of
	 * XML object.
	 */
	public function junos_active(){

		$this->active_element->set_attribute("active", "active");
	}

	/**
	 * Sets the attribute ("inactive","inactive") for the active element of
	 * XML object.
	 */
	public function junos_deactivate(){

		$this->active_element->set_attribute("inactive", "inactive");
	}

	/**
	 * Sets the attribute ("rename") and ("name") for the active element of
	 * XML object.
	 * @param $toBeRenamed
	 * @param $newName
	 */
	public function junos_rename($toBeRenamed, $newName){

		$this->active_element->set_attribute("rename", $toBeRenamed);
		$this->active_element->set_attribute("name", $newName);
	}

	/**
	 * Sets the attribute ("insert") and ("name") for the active eleent of
	 * XML object.
	 * @param $before
	 * @param $name
	 */
	public function junos_insert($before, $name){

		$this->active_element->set_attribute("insert", $before);
		$this->active_element->set_attribute("name", $name);
	}

	/**
	 * @param $arr
	 * @return bool
	 */
	private function is_assoc($arr){

		return (is_array($arr) && count(array_filter(array_keys($arr), 'is_string')) == count($arr));
	}

	/**
	 * Append an element under the active elment of XML object.
	 * This function takes variable no of arguments.
	 * <ol>
	 * <li> only element: Append an element under the active element
	 * of XML object. The new element now becomes the active element.</li>
	 * <li>one element and one text : Append an element, with text, under the
	 * active element of XML object. The new element now becomes
	 * the active element.</li>
	 * <li>one element and an arrya of text values : Append multiple elements,
	 * with same name but differrent text under the active element of XML object.</li>
	 * <li>An associative array : Append multiple elements with different names
	 * and different text, under the active element of XML object.In this,
	 * each entry contains element name as the key and text value as the
	 * key value.</li>
	 * <li>An element and an associative array : Append an element under the
	 * active element of XML object. The new element now becomes the
	 * active element.Then,append multiple elements wiht different names
	 * and different text, under the new active element.</li></ol>
	 * @return XML|null The modified XML after apending the element.
	 */
	public function append(){

		$numOfArgs = func_num_args();
		if($numOfArgs < 1 && $numOfArgs > 2){
			die("Invalid number of arguments");
		}
		if($numOfArgs == 2){
			if( !is_array(func_get_arg(1)) && !$this->is_assoc(func_get_arg(1))){
				$newElement = $this->owner_doc->createElement(func_get_arg(0));
				$textNode   = $this->owner_doc->createTextNode(func_get_arg(1));
				$this->active_element->appendChild($newElement);
				$newElement->appendChild($textNode);

				return new XML($newElement, $this->owner_doc);
			}else{
				if(gettype(func_get_arg(1)) == "array" && !$this->is_assoc(func_get_arg(1))){
					foreach(func_get_arg(1) as $text){
						$newElement = $this->owner_doc->createElement(func_get_arg(0));
						$textNode   = $this->owner_doc->createTextNode($text);
						$this->active_element->appendChild($newElement);
						$newElement->appendChild($textNode);
					}

					return null;
				}else{
					if($this->is_assoc(func_get_arg(1))){
						$newElement = $this->owner_doc->createElement(func_get_arg(0));
						$this->active_element->appendChild($newElement);
						$newXML = new XML($newElement, $this->owner_doc);
						$newXML->append(func_get_arg(1));

						return $newXML;
					}
				}
			}
		}else{
			if($this->is_assoc(func_get_arg(0))){
				foreach(func_get_arg(0) as $property => $value){
					$this->append($property, $value);
				}
			}else{
				$newElement = $this->owner_doc->createElement(func_get_arg(0));
				$this->active_element->appendChild($newElement);

				return new XML($newElement, $this->owner_doc);
			}
		}
	}

	/**
	 * Add a sibling with the active element of XML object.
	 * This function takes variable number of arguments.
	 * <ol>
	 * <li>An element : Add a sibling element with the active element
	 * of XML object.</li>
	 * <li>An element and a text value: Append a sibling element, with text,
	 * with the active element of XML object.</li></ol>
	 */
	public function add_sibling(){

		$numOfArgs = func_num_args();
		if($numOfArgs < 1 && $numOfArgs > 2){
			die("Invalid number of arguments");
		}
		$newElement = $this->owner_doc->createElement(func_get_arg(0));
		$parentNode = $this->active_element->parentNode;
		if($numOfArgs == 2){
			$textNode = $this->owner_doc->createTextNode(func_get_arg(1));
			$newElement->appendChild($textNode);
		}
		$parentNode->appendChild($newElement);
	}

	/**
	 * Add multiple siblings
	 * This function takes variable number of arguments.
	 * <ol>
	 * <li>An element and an array of text values : Add multiple sibling elements,
	 * with same names but different text, with the active element of XML object.
	 * </li>
	 * <li>An associative array : Add multiple siblings with different names
	 * and different text, with the active element of XML objcet.In this,
	 * each entry containing element name as the key and text value as the
	 * key value.</li></ol>
	 */
	public function add_siblings(){

		$numOfArgs = func_num_args();
		if($numOfArgs < 1 && $numOfArgs > 2){
			die("Invalid number of arguments");
		}
		$parentNode = $this->active_element->parentNode;
		if($numOfArgs == 1 && $this->is_assoc(func_get_arg(0))){
			$inter                = $this->active_element;
			$this->active_element = $parentNode;
			foreach(func_get_arg(0) as $property => $value)
				$this->append($property, $value);
			$this->active_element = $inter;
		}else{
			foreach(func_get_arg(1) as $text){
				$element  = $this->owner_doc->createElement(func_get_arg(0));
				$textNode = $this->owner_doc->createTextNode($text);
				$element->appendChild($textNode);
				$parentNode->appendChild($element);
			}
		}
	}

	/**
	 * Append multiple elements under the active element of XML object,
	 * by specifying the path. The bottom most hierarchy element now becomes
	 * the active element.
	 * @param path The path to be added
	 * @return XML The modified XML.
	 * @example  to add the hierarchy:
	 *           <a>
	 *           <b>
	 *           </c>
	 *           </b>
	 *           </a>
	 *           The path should be "a/b/c"
	 */
	public function add_path($path){

		$elements   = explode("/", $path);
		$newElement = null;
		foreach($elements as $value){
			$newElement = $this->owner_doc->createElement($value);
			$this->active_element->appendChild($newElement);
			$this->active_element = $newElement;
		}

		return new XML($newElement, $this->owner_doc);
	}

	/**
	 * Get the XML String of the XML object.
	 * @return string of the XML data as a string
	 */
	public function toString(){

		//should return the same I just trust simpleXml more
		//in case somthing went horribly wrong and no simplexml use the doc
		return isset($this->simplexml) ? $this->simplexml->asXML() : $this->owner_doc->saveXML();
	}

	/**
	 * Convert the XML object to Array.
	 * @param string $xpath to xml tag @example $xpath = '//ns:tag_name'
	 * @return false|array of the XML data
	 */
	public function toArray($xpath = ''){

		//simplexml should always exist
		$simplexml = ($xpath !== '') ? $this->simplexml->xpath($xpath) : $this->simplexml;

		return json_decode(json_encode($simplexml), true);
	}

	/**
	 * Find the text value of an element.
	 * @param string|array String based array of elements which determine the hierarchy.
	 * @return string The text value of the element.
	 * @example for the below XML:
	 *                <rpc-reply>
	 *                <environment-information>
	 *                <environment-item>
	 *                <name>FPC 0 CPU</name>
	 *                <temperature>
	 *                To find out the text value of temperature node, the array
	 *                should be- {"environment-information","environment-item","name~FPC 0 CPU","temperature"}
	 */
	public function find_value($list){

		if( !is_array($list)){
			$list = array($list);
		}
		$root             = $this->owner_doc->documentElement;
		$nextElement      = $root;
		$nextElementFound = false;
		for($k = 0; $k < sizeof($list); $k++){
			$nextElementFound = false;
			$nextElementName  = $list[$k];
			if( !strpos($nextElementName, "~")){
				$nextElementList = $root->getElementsByTagName($nextElementName);
				$n2nString       = null;
				if($k < sizeof($list) - 1){
					$n2nString = $list[$k + 1];
				}
				if($n2nString != null && strpos($n2nString, "~")){
					$n2nText        = substr($n2nString, strpos($n2nString, "~") + 1);
					$n2nElementName = substr($n2nString, 0, strpos($n2nString, "~"));
					for($i = 0; $i < sizeof($nextElementList); $i++){
						$nextElement = $nextElementList->item($i);
						$n2nElement  = $nextElement->getElementsByTagName($n2nElementName)->item(0);
						$text        = $n2nElement->firstChild->nodeValue;
						trim($text);
						if($text != null && $text == $n2nText){
							$nextElementFound = true;
							break;
						}
					}
					if( !$nextElementFound){
						return null;
					}
				}else{
					$nextElement = $nextElementList->item(0);
				}
			}else{
				continue;
			}
		}
		if($nextElement == null){
			return null;
		}
		$value = $nextElement->firstChild->nodeValue;
		if($value == null){
			return null;
		}

		return trim($value);
	}
}

?>
