<?php

namespace Finn\FinnClient;

use Finn\RestClient\CurlClient;
use Finn\RestClient\ClientInterface;

class FinnClient
{
    private $restClient = null;
    private $apiUrl = "https://cache.api.finn.no/iad/";

    /*
    *	Constructor
    *	@param $restClient: A restclient implementing ClientInterface
    */
    public function __construct(ClientInterface $restClient)
    {
        $this->restClient = $restClient;
    }

    /*
    *	Do a search for properties
    *	@param $type: finn realestate type "realestate-homes"
    *	@param $queryParams: Array with query parameters
    *   @return Resultset
    */
    public function search($type, $queryParams)
    {
        $url = $this->apiUrl . 'search/' . $type . '?' . http_build_query($queryParams);
        $rawData = $this->restClient->send($url);
        //parse dataene til array med objekter
        $resultSet = $this->parseResultset($rawData);

        return $resultSet;
    }

    public function getObjectByUrl($url)
    {
        $rawData = $this->restClient->send($url);

        //parse dataene til array med objekter
        if (isset($rawData)) {
            $entry = new \SimpleXMLElement($rawData);
            $ns = $entry->getNamespaces(true);

            $resultSet = $this->parseEntry($entry, $ns);

            return $resultSet;
        }
    }

    /*
    *	Get single object with finncode
    *   @param $type: finn realestate type "realestate-homes"
    *	@param $finncode: The ads finncode
    */
    public function getObject($type, $finncode)
    {
        $url = $this->apiUrl . 'ad/' . $type . '/' . $finncode;
        $rawData = $this->restClient->send($url);

        //parse dataene til array med objekter
        if (isset($rawData)) {
            $entry = new \SimpleXMLElement($rawData);
            $ns = $entry->getNamespaces(true);

            $resultSet = $this->parseEntry($entry, $ns);

            return $resultSet;
        }
    }

    /*
    *
    *
    */
    private function parseEntry($entry, $ns)
    {
        $property = new Property();

        $property->id = (string)$entry->children($ns['dc'])->identifier;
        $property->title = (string)$entry->title;
        $property->updated = (string)$entry->updated;
        $property->published = (string)$entry->published;

        $links = array();

        foreach ($entry->link as $link) {
            $rel = $link->attributes()->rel;
            $ref = $link->attributes()->href;
            $links["$rel"] = "$ref";
        }
        $property->links = $links;

        $isPrivate = "false";
        $status = "";
        $adType = "";

        foreach ($entry->category as $category) {
            if ($category->attributes()->scheme == "urn:finn:ad:private") {
                $isPrivate = $category->attributes()->term;
            }
            //if disposed == true, show the label
            if ($category->attributes()->scheme == "urn:finn:ad:disposed") {
                if ($category->attributes()->term == "true") {
                    $status = $category->attributes()->label;
                }
            }
            if ($category->attributes()->scheme == "urn:finn:ad:type") {
                $adType = $category->attributes()->label;
            }
        }

        $property->isPrivate = (string)$isPrivate;
        $property->status = (string)$status;
        $property->adType = (string)$adType;

        $property->georss = (string)$entry->children($ns['georss'])->point;
        $location = $entry->children($ns['finn'])->location;
        $property->city = (string)$location->children($ns['finn'])->city;
        $property->address = (string)$location->children($ns['finn'])->address;
        $property->postalCode = (string)$location->children($ns['finn'])->{'postal-code'};

        $contacts = array();
        $work = '';
        $mobile = '';
        $fax = '';

        foreach ($entry->children($ns['finn'])->contact as $contact) {
            $name = (string)$contact->children()->name;
            $title = (string)$contact->attributes()->title;
            foreach ($contact->{'phone-number'} as $numbers) {
                switch ($numbers->attributes()) {
                    case 'work':
                        $work = (string)$numbers;
                        break;
                    case 'mobile':
                        $mobile = (string)$numbers;
                        break;
                    case 'fax':
                        $fax = (string)$numbers;
                        break;
                }
            }
            array_push($contacts, array(
                'name' => $name,
                'title' => $title,
                'work' => $work,
                'mobile' => $mobile,
                'fax' => $fax
            ));
        }
        $property->contacts = $contacts;

        $img = array();
        if ($entry->children($ns['media']) && $entry->children($ns['media'])->content->attributes()) {
            //$img = $entry->children($ns['media'])->content->attributes();
            foreach ($entry->children($ns['media'])->content as $content) {
                $img[] = current($content->attributes());
            }
        }
        $property->img = $img;

        $property->author = (string)$entry->author->name;

        $adata = $entry->children($ns['finn'])->adata;
        $livingSizeFrom = 0;
        $livingSizeTo = 0;
        $propertyType = "";
        $numberOfBedrooms = 0;
        $ownershipType = "";
        $usableSize = "";
        $primarySize = "";
        $ingress = "";
        $situation = "";

        $plot_area = '';
        $plot_owned = '';

        $energy_label = '';
        $energy_label_color_code = '';

        $cadastral_unit_number = '';
        $property_unit_number = '';
        $section_number = '';

        $viewings = array();

        $facilities = array();
        $generalText = array();

        foreach ($adata->children($ns['finn'])->field as $field) {
            if ($field->attributes()->name == 'no_of_bedrooms') {
                $numberOfBedrooms = $field->attributes()->value;
            }
            if ($field->attributes()->name == 'property_type') {
                $propertyType = $field->children($ns['finn'])->value;
            }
            if ($field->attributes()->name == 'ownership_type') {
                $ownershipType = $field->attributes()->value;
            }
            if ($field->attributes()->name == 'size') {
                foreach ($field->children($ns['finn'])->field as $sizeField) {
                    if ($sizeField->attributes()->name == "usable") {
                        $usableSize = $sizeField->attributes()->value;
                    }
                    if ($sizeField->attributes()->name == "primary") {
                        $primarySize = $sizeField->attributes()->value;
                    }
                    $livingSizeFrom = $sizeField->attributes()->from;
                    $livingSizeTo = $sizeField->attributes()->to;
                }
            }

            if ($field->attributes()->name == 'facilities') {
                foreach ($field->children($ns['finn'])->value as $facility) {
                    $facilities[] = (string)$facility;
                }
            }

            if ($field->attributes()->name == 'general_text') {
                $i = 0;
                foreach ($field->children($ns['finn'])->value as $text) {
                    foreach ($text->children($ns['finn'])->field as $t) {
                        if ($t->attributes()->name == "title") {
                            $generalText[$i]['title'] = (string)$t->attributes()->value;
                        }
                        if ($t->attributes()->name == "value") {
                            $generalText[$i]['value'] = (string)$t;
                        }
                    }
                    $i++;
                }
            }
            if ($field->attributes()->name == 'ingress') {
                $ingress = (string)$field;
            }
            if ($field->attributes()->name == 'situation') {
                $situation = (string)$field;
            }
            if ($field->attributes()->name == 'plot') {
                foreach ($field->children($ns['finn'])->field as $plotField) {
                    if ($plotField->attributes()->name == 'area') {
                        $plot_area = (string)$plotField->attributes()->value;
                    }
                    if ($plotField->attributes()->name == 'owned') {
                        $plot_owned = true;
                    }
                }
            }
            if ($field->attributes()->name == 'energy_label') {
                $energy_label = (string) $field->attributes()->value;
            }
            if ($field->attributes()->name == 'energy_label_color_code') {
                $energy_label_color_code = (string) $field->attributes()->value;
            }
            if ($field->attributes()->name == 'cadastres') {
                foreach ($field->children($ns['finn'])->value[0]->children($ns['finn'])->field as $cadastreField) {
                    if ($cadastreField->attributes()->name == 'cadastral_unit_number') {
                        $cadastral_unit_number = (string) $cadastreField->attributes()->value;
                    }
                    if ($cadastreField->attributes()->name == 'property_unit_number') {
                        $property_unit_number = (string) $cadastreField->attributes()->value;
                    }
                    if ($cadastreField->attributes()->name == 'section_number') {
                        $section_number = (string) $cadastreField->attributes()->value;
                    }
                }
            }
            if ($field->attributes()->name == 'viewings') {
                $i = 0;
                foreach ($field->children($ns['finn'])->value as $viewing) {
                    foreach ($viewing->children($ns['finn'])->field as $viewingField) {
                        if ($viewingField->attributes()->name == 'date') {
                            $viewings[$i]['date'] = (string) $viewingField;
                        }
                        if ($viewingField->attributes()->name == 'from') {
                            $viewings[$i]['from'] = (string) $viewingField->attributes()->value;
                        }
                        if ($viewingField->attributes()->name == 'to') {
                            $viewings[$i]['to'] = (string) $viewingField->attributes()->value;
                        }
                    }

                    $i++;
                }
            }
        }

        $property->ingress = $ingress;
        $property->situation = $situation;
        $property->plot = [
            'area' => $plot_area,
            'owned' => $plot_owned
        ];
        $property->energy = [
            'label' => $energy_label,
            'label_color_code' => $energy_label_color_code
        ];
        $property->cadastres = [
            'cadastral_unit_number' => $cadastral_unit_number,
            'property_unit_number' => $property_unit_number,
            'section_number' => $section_number
        ];
        $property->facilities = $facilities;
        $property->generalText = $generalText;
        $property->livingSizeFrom = (string)$livingSizeFrom;
        $property->livingSizeTo = (string)$livingSizeTo;
        $property->propertyType = (string)$propertyType;
        $property->numberOfBedrooms = (string)$numberOfBedrooms;
        $property->ownershipType = (string)$ownershipType;
        $property->usableSize = (string)$usableSize;
        $property->primarySize = (string)$primarySize;
        $property->viewings = $viewings;

        $mainPrice = "";
        $totalPrice = "";
        $collectiveDebt = "";
        $sharedCost = "";
        $estimatedValue = "";
        $sqmPrice = "";
        $mainPriceFrom = "";
        $mainPriceTo = "";

        foreach ($adata->children($ns['finn'])->price as $price) {
            if ($price->attributes()->name == 'main') {
                $mainPrice = $price->attributes()->value;
                $mainPriceFrom = $price->attributes()->from;
                $mainPriceTo = $price->attributes()->to;
            }
            if ($price->attributes()->name == 'total') {
                $totalPrice = $price->attributes()->value;
            }
            if ($price->attributes()->name == 'collective_debt') {
                $collectiveDebt = $price->attributes()->value;
            }
            if ($price->attributes()->name == 'shared_cost') {
                $sharedCost = $price->attributes()->value;
            }
            if ($price->attributes()->name == 'estimated_value') {
                $estimatedValue = $price->attributes()->value;
            }
            if ($price->attributes()->name == 'square_meter') {
                $sqmPrice = $price->attributes()->value;
            }
        }
        $property->mainPrice = (string)$mainPrice;
        $property->mainPriceFrom = (string)$mainPriceFrom;
        $property->mainPriceTo = (string)$mainPriceTo;
        $property->totalPrice = (string)$totalPrice;
        $property->collectiveDebt = (string)$collectiveDebt;
        $property->sharedCost = (string)$sharedCost;
        $property->estimatedValue = (string)$estimatedValue;
        $property->sqmPrice = (string)$sqmPrice;


        return $property;
    }

    //Returns an array of objects
    private function parseResultset($rawData)
    {
        $resultset = new Resultset();

        //parse the xml and get namespaces (needed later to extract attributes and values)
        $xmlData = new \SimpleXMLElement($rawData);
        $ns = $xmlData->getNamespaces(true);

        //search data:
        $resultset->title = (string)$xmlData->title;
        $resultset->subtitle = (string)$xmlData->subtitle;
        //$resultset->totalResults = (string)$xmlData->children($ns['os'])->totalResults;

        //navigation links
        $links = array();
        foreach ($xmlData->link as $link) {
            $rel = $link->attributes()->rel;
            $ref = $link->attributes()->href;
            $links["$rel"] = "$ref";
        }
        $resultset->links = $links;
        //entry data

        //get each entry for simpler syntax when looping through them later
        $entries = array();
        foreach ($xmlData->entry as $entry) {
            array_push($entries, $entry);
        }

        $propertyList = array();
        foreach ($entries as $entry) {
            $property = $this->parseEntry($entry, $ns);
            $propertyList[] = $property;
        }

        $resultset->results = $propertyList;

        return $resultset;
    }
}
