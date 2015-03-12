<?php
/**
 * Copyright (c) 2012-2013 Aalto University and University of Helsinki
 * MIT License
 * see LICENSE.txt for more information
 */

/**
 * Dataobject for a single concept.
 */

class Concept extends VocabularyDataObject
{
  /**
   * Stores a label string if the concept has been found through
   * a altLabel/label in a another language than the ui.
   */
  private $foundby;
  /** Type of foundby match: 'alt', 'hidden' or 'lang' */
  private $foundbytype;
  /** the EasyRdf_Graph object of the concept */
  private $graph;
  /** the preferred order of properties */
  public $order;
  private $clang;

  /** concept properties that should not be shown to users */
  private $DELETED_PROPERTIES = array(
    'skosext:broaderGeneric',		# these are remnants of bad modeling
    'skosext:broaderPartitive',		#

    'skos:hiddenLabel',			# because it's supposed to be hidden

    'skos:topConceptOf',		# because it's too technical, not relevant for users
    'skos:inScheme',			# should be evident in any case
    'skos:member'			    # this is shouldn't be shown on the group page
  );

  /** related concepts that should be shown to users in the appendix */
  private $MAPPING_PROPERTIES = array(
    'skos:exactMatch', 
    'skos:narrowMatch', 
    'skos:broadMatch', 
    'skos:closeMatch', 
    'skos:relatedMatch', 
    'rdfs:seeAlso', 
    'owl:sameAs' 
  );

  /**
   * Initializing the concept object requires the following parameters.
   * @param Model $model
   * @param Vocabulary $vocab
   * @param EasyRdf_Resource $resource
   * @param EasyRdf_Graph $graph
   */
  public function __construct($model, $vocab, $resource, $graph, $clang)
  {
    parent::__construct($model, $vocab, $resource);
    $this->order = array("rdf:type", "dc:isReplacedBy", "skos:definition", "skos:broader", "skos:narrower", "skos:related", "skos:altLabel", "skosmos:memberOf", "skos:note", "skos:scopeNote", "skos:historyNote", "rdfs:comment", "dc11:source", "dc:source", "skos:prefLabel");
    $this->graph = $graph;
    $this->clang = $clang ? $clang : $this->lang;
  }

  /**
   * Returns the concept uri.
   * @return string
   */
  public function getUri()
  {
    return $this->resource->getUri();
  }
  
  /**
   * Returns a boolean value indicating if the concept has been deprecated.
   * @return boolean 
   */
  public function getDeprecated()
  {
    foreach ($this->resource->all('rdf:type') as $type)
      if (strpos($type->getUri(), 'DeprecatedConcept'))
        return true;
    return false;
  }
   
  /**
   * Returns a label for the concept in the ui language or if not possible in any language.
   * @return string
   */
  public function getLabel($lang='')
  {
    // 1. label in current language
    if ($this->resource->label($lang) !== null)
      return $this->resource->label($lang);
    // 2. label in any language
    $label = $this->resource->label();
    // if the label lang code is a subset of the ui lang eg. en-GB
    if ($label !== null && strpos($label->getLang(), $this->lang . '-') === 0)
      return $label->getValue();
    if ($label !== null)
      return $label->getValue() . " (" . $label->getLang() . ")";
    // empty
    return "";
  }
  
  /**
   * Returns a notation for the concept or null if it has not been defined.
   * @return string eg. '999'
   */
  public function getNotation()
  {
    $notation = $this->resource->get('skos:notation');
    if ($notation !== null)
      return $notation->getValue();
    return null; 
  }

  /**
   * Returns the vocabulary identifier string or null if that is not available.
   * @return string
   */
  public function getVocab()
  {
    return $this->vocab ? $this->vocab->getId() : null;
  }
  
  /**
   * Returns the vocabulary shortname string or id if that is not available.
   * @return string
   */
  public function getShortName()
  {
    return $this->vocab ? $this->vocab->getShortName() : null;
  }


  /**
   * Setter for the $foundby property.
   * @param string $label label that was matched
   * @param string $type type of match: 'alt', 'hidden', or 'lang'
   */
  public function setFoundBy($label, $type)
  {
    $this->foundby = $label;
    $this->foundbytype = $type;
  }

  /**
   * Getter for the $foundby property.
   * @return string
   */
  public function getFoundBy()
  {
    return $this->foundby;
  }

  /**
   * Getter for the $foundbytype property.
   * @return string
   */
  public function getFoundByType()
  {
    return $this->foundbytype;
  }

  public function getMappingProperties()
  {
    $properties = array();
    $members_array = array();

    $long_uris = $this->resource->propertyUris();
    foreach ($long_uris as &$prop) {
      if (EasyRdf_Namespace::shorten($prop)) // shortening property labels if possible
        $prop = $sprop = EasyRdf_Namespace::shorten($prop);
      else
        $sprop = "<$prop>"; // EasyRdf requires full URIs to be in angle brackets

      // Iterating through every resource and adding these to the data object.
      foreach ($this->resource->allResources($sprop) as $val) {
        if (in_array($prop, $this->MAPPING_PROPERTIES)) {

          // checking if the target vocabulary can be found at the skosmos endpoint
          $exuri = $val->getUri();
          $exvoc = $this->model->guessVocabularyFromURI($exuri);
          // if not querying the uri itself
          if (!$exvoc) {
            $response = null;
            // if told to do so in the vocabulary configuration
            if ($this->vocab->getExternalResourcesLoading()) 
              $response = $this->model->getResourceFromUri($exuri);
            if ($response) {
              $properties[$prop][] = new ConceptMappingPropertyValue($this->model, $this->vocab, $response, $prop);
            }
          } 
          else
            $properties[$prop][] = new ConceptMappingPropertyValue($this->model, $this->vocab, $val, $prop);
        }
      }
    }

    // sorting the properties to a order preferred in the Skosmos concept page.
    $properties = $this->arbitrarySort($properties);

    // clean up: remove unwanted properties
    foreach ($this->DELETED_PROPERTIES as $prop) {
      if (isset($properties[$prop]))
        unset($properties[$prop]);
    }

    $ret = array();
    foreach ($properties as $prop => $values) {
      // sorting the values by vocabulary name for consistency.
      $sortedvalues = array();
      foreach ($values as $value) {
        $sortedvalues[$value->getVocabName() . $value . $value->getUri()] = $value; 
      }
      ksort($sortedvalues);
      $values = $sortedvalues;
      $propres = new EasyRdf_Resource($prop, $this->graph);
      $proplabel = $propres->label($this->lang); // current language
      if (!$proplabel) $proplabel = $propres->label(); // any language
      $propobj = new ConceptProperty($prop, $proplabel, $values);
      if ($propobj->getLabel()) // only display properties for which we have a label
        $ret[] = $propobj;
    }

    return $ret;
  }

  /**
   * Iterates over all the properties of the concept and returns those in an array.
   * @return array
   */
  public function getProperties()
  {
    $properties = array();
    $narrowers_by_uri = array(); 
    $in_a_collection = array();
    $members_array = array();
    $long_uris = $this->resource->propertyUris();
    $duplicates = array();

    // looking for collections and linking those with their narrower concepts
    if ($this->vocab->getArrayClassURI() !== null) {
      $collections = $this->graph->allOfType($this->vocab->getArrayClassURI()); 
      if (sizeof($collections) > 0) { 
        // indexing the narrowers once to avoid iterating all of them with every collection
        foreach ($this->resource->allResources('skos:narrower') as $narrower)
          $narrowers_by_uri[$narrower->getUri()] = $narrower;

        foreach ($collections as $coll) {
          $current_collection_members = $this->getCollectionMembers($coll, $narrowers_by_uri);
          foreach ($current_collection_members as $collection) {
            if ($collection->getSubMembers()) {
              $submembers = $collection->getSubMembers();
              foreach ($submembers as $member)
                $in_a_collection[$member->getUri()] = true;
            }
          }

          if ($collection->getSubMembers())
            $members_array = array_merge($current_collection_members, $members_array);
        }
      }
    }

    foreach ($long_uris as &$prop) {
      if (EasyRdf_Namespace::shorten($prop)) // shortening property labels if possible
        $prop = $sprop = EasyRdf_Namespace::shorten($prop);
      else
        $sprop = "<$prop>"; // EasyRdf requires full URIs to be in angle brackets

      // searching for subproperties of literals too
      foreach ($this->graph->allResources($prop, 'rdfs:subPropertyOf') as $subi) {
        $suburi = EasyRdf_Namespace::shorten($subi->getUri());
        if (!isset($suburi))
          $suburi = $subi->getUri();
        $duplicates[$suburi] = $prop;
      }

      // Iterating through every literal and adding these to the data object.
      foreach ($this->resource->allLiterals($sprop) as $val) {
        $literal = new ConceptPropertyValueLiteral($val, $prop, $this->clang);
        // checking that the literal has either the correct language or no language set
        if ($literal->getLang() == null || $literal->getLang() == $this->clang)
          $properties[$prop][$literal->getLabel()] = $literal; 
      }
      
      // Iterating through every resource and adding these to the data object.
      foreach ($this->resource->allResources($sprop) as $val) {
        // skipping narrower concepts which are already shown in a collection
        if ($prop === 'skos:narrower' && array_key_exists($val->getUri(), $in_a_collection))
          continue;
        // hiding rdf:type property if it's just skos:Concept
        if ($prop === 'rdf:type' && $val->shorten() === 'skos:Concept') 
          continue;
        // handled by getMappingProperties()
        if (in_array($prop, $this->MAPPING_PROPERTIES))
          continue;

        $propval = new ConceptPropertyValue($this->model, $this->vocab, $val, $prop);
        $label = $propval->getLabel($this->clang);

        if (is_string($label)) 
          $properties[$prop][$label] = $propval;
        elseif ($label->getValue())
          $properties[$prop][$label->getValue()] = $propval;
      }
    }
    
    // if skos:narrower properties are actually groups we need to remove duplicates.
    foreach($members_array as $group) {
      $properties['skos:narrower'][] = $group;
    }

    // clean up: remove unwanted properties
    foreach ($this->DELETED_PROPERTIES as $prop) {
      if (isset($properties[$prop]))
        unset($properties[$prop]);
    }
    
    // sorting the properties to a order preferred in the Skosmos concept page.
    $properties = $this->arbitrarySort($properties);

    $propertyValues = array();

    $ret = array();
    foreach ($properties as $prop => $values) {
      $propres = new EasyRdf_Resource($prop, $this->graph);
      $proplabel = $propres->label($this->lang); // current language
      if (!$proplabel) $proplabel = $propres->label(); // any language
      foreach ($values as $value) {
        $vallabel = $value->getLabel();
        if (!is_string($vallabel)) continue;
        $propertyValues[$vallabel][] = $propres->getUri();
      }
      $propobj = new ConceptProperty($prop, $proplabel, $values);
      if ($propobj->getLabel()) // only display properties for which we have a label
        $ret[$prop] = $propobj;
    }

    foreach ($propertyValues as $value => $propnames) {
      // if the value of prefLabel and rdfs:label are the same we can remove rdfs:label as it's redundant
      if (in_array('skos:prefLabel', $propnames) && in_array('rdfs:label', $propnames)) {
        unset($ret['rdfs:label']);
      }
      // if there are multiple properties with the same string value.
      if (count($propnames) > 1) {
        foreach( $propnames as $property)
          // if there is a more accurate property delete the more generic one.
          if (isset($duplicates[$property])) {
            unset($ret[$property]);
          }
      }
    }

    return $ret;
  }

  /**
   * Gets the members of a specific collection.
   * @param $coll
   * @param array containing all narrowers as EasyRdf_Resource
   * @return array containing ConceptPropertyValue objects
   */
  private function getCollectionMembers($coll, $narrowers)
  {
    $members_array = Array();
    $members_array[$coll->getUri()] = new ConceptPropertyValue($this->model, $this->vocab, $coll, 'skos:narrower');
    foreach ($coll->allResources('skos:member') as $member) {
      if (array_key_exists($member->getUri(), $narrowers)) {
        $narrower = $narrowers[$member->getUri()];
        if (isset($narrower))
          $members_array[$coll->getUri()]->addSubMember(new ConceptPropertyValue($this->model, $this->vocab, $narrower, 'skos:member'), $this->clang);
      }
    }

    return $members_array;
  }

  /**
   * Gets the groups the concept belongs to.
   */
  public function getGroupProperties() {
    // finding out if the concept is a member of some group
    $groups = array();
    $reverseResources = $this->graph->resourcesMatching('skos:member', $this->resource);
    if (isset($reverseResources)) {
      $arrayClassURI = $this->vocab !== null ? $this->vocab->getArrayClassURI() : null;
      $arrayClass = $arrayClassURI !== null ? EasyRdf_Namespace::shorten($arrayClassURI) : null;
      foreach ($reverseResources as $reverseResource) {
        $property = in_array($arrayClass, $reverseResource->types()) ? "skosmos:memberOfArray" : "skosmos:memberOf" ;
        $exvoc = $this->model->guessVocabularyFromURI($reverseResource->getUri());
        $exvocab = $exvoc ? $exvoc->getId() : null;
        $reverseUri = $reverseResource->getUri(null);
        $label = $reverseResource->label($this->lang) ? $reverseResource->label($this->lang) : $reverseResource->label();
        $labelLang = $label ? $label->getLang() : null;
        $label = $label ? $label->getValue() : null;
        $super = $reverseResource->get('isothes:superGroup');
        while(isset($super)) {
          $groups[] = new ConceptPropertyValue($this->model, $this->vocab, $super, 'isothes:superGroup');
          $super = $super->get('isothes:superGroup');
        }
        $groups[] = new ConceptPropertyValue($this->model, $this->vocab, $reverseResource, $property);
      }
    }
    return $groups;
  }


  /**
   * Gets the values for the property in question in all other languages than the ui language.
   */
  public function getForeignLabels()
  {
    global $LANGUAGES;
    $labels = array();
    foreach ($this->resource->allLiterals('skos:prefLabel') as $lit) {
      // filtering away subsets of the current language eg. en vs en-GB
      if ($lit->getLang() != $this->clang && strpos($lit->getLang(), $this->lang . '-') !== 0)
        $labels[Punic\Language::getName($lit->getLang(), $this->lang)][]  = new ConceptPropertyValueLiteral($lit, 'skos:prefLabel');
    }
    foreach ($this->resource->allLiterals('skos:altLabel') as $lit) {
      // filtering away subsets of the current language eg. en vs en-GB
      if ($lit->getLang() != $this->clang && strpos($lit->getLang(), $this->lang . '-') !== 0)
        $labels[Punic\Language::getName($lit->getLang(), $this->lang)][]  = new ConceptPropertyValueLiteral($lit, 'skos:altLabel');
    }
    ksort($labels);
    return $labels;
  }

}
