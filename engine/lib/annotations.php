<?php
/**
 * Elgg annotations
 * Functions to manage object annotations.
 *
 * @package Elgg
 * @subpackage Core
 */

/**
 * Convert a database row to a new ElggAnnotation
 *
 * @param stdClass $row Db row result object
 *
 * @return ElggAnnotation
 */
function row_to_elggannotation($row) {
	if (!($row instanceof stdClass)) {
		return $row;
	}

	return new ElggAnnotation($row);
}

/**
 * Get a specific annotation.
 *
 * @param int $annotation_id Annotation ID
 *
 * @return ElggAnnotation
 */
function get_annotation($annotation_id) {
	global $CONFIG;

	$annotation_id = (int) $annotation_id;
	$access = get_access_sql_suffix("a");

	$query = "SELECT a.*, n.string as name, v.string as value"
		. " from {$CONFIG->dbprefix}annotations a"
		. " JOIN {$CONFIG->dbprefix}metastrings n on a.name_id = n.id"
		. " JOIN {$CONFIG->dbprefix}metastrings v on a.value_id = v.id"
		. " where a.id=$annotation_id and $access";

	return row_to_elggannotation(get_data_row($query));
}

/**
 * Create a new annotation.
 *
 * @param int    $entity_guid Entity Guid
 * @param string $name        Name of annotation
 * @param string $value       Value of annotation
 * @param string $value_type  Type of value
 * @param int    $owner_guid  Owner of annotation
 * @param int    $access_id   Access level of annotation
 *
 * @return int|bool id on success or false on failure
 */
function create_annotation($entity_guid, $name, $value, $value_type,
$owner_guid, $access_id = ACCESS_PRIVATE) {
	global $CONFIG;

	$result = false;

	$entity_guid = (int)$entity_guid;
	//$name = sanitise_string(trim($name));
	//$value = sanitise_string(trim($value));
	$value_type = detect_extender_valuetype($value, sanitise_string(trim($value_type)));

	$owner_guid = (int)$owner_guid;
	if ($owner_guid == 0) {
		$owner_guid = elgg_get_logged_in_user_guid();
	}

	$access_id = (int)$access_id;
	$time = time();

	// Add the metastring
	$value = add_metastring($value);
	if (!$value) {
		return false;
	}

	$name = add_metastring($name);
	if (!$name) {
		return false;
	}

	$entity = get_entity($entity_guid);

	if (elgg_trigger_event('annotate', $entity->type, $entity)) {
		system_log($entity, 'annotate');

		// If ok then add it
		$result = insert_data("INSERT into {$CONFIG->dbprefix}annotations
			(entity_guid, name_id, value_id, value_type, owner_guid, time_created, access_id) VALUES
			($entity_guid,'$name',$value,'$value_type', $owner_guid, $time, $access_id)");

		if ($result !== false) {
			$obj = get_annotation($result);
			if (elgg_trigger_event('create', 'annotation', $obj)) {
				return $result;
			} else {
				// plugin returned false to reject annotation
				delete_annotation($result);
				return FALSE;
			}
		}
	}

	return $result;
}

/**
 * Update an annotation.
 *
 * @param int    $annotation_id Annotation ID
 * @param string $name          Name of annotation
 * @param string $value         Value of annotation
 * @param string $value_type    Type of value
 * @param int    $owner_guid    Owner of annotation
 * @param int    $access_id     Access level of annotation
 *
 * @return bool
 */
function update_annotation($annotation_id, $name, $value, $value_type, $owner_guid, $access_id) {
	global $CONFIG;

	$annotation_id = (int)$annotation_id;
	$name = (trim($name));
	$value = (trim($value));
	$value_type = detect_extender_valuetype($value, sanitise_string(trim($value_type)));

	$owner_guid = (int)$owner_guid;
	if ($owner_guid == 0) {
		$owner_guid = elgg_get_logged_in_user_guid();
	}

	$access_id = (int)$access_id;

	$access = get_access_sql_suffix();

	// Add the metastring
	$value = add_metastring($value);
	if (!$value) {
		return false;
	}

	$name = add_metastring($name);
	if (!$name) {
		return false;
	}

	// If ok then add it
	$result = update_data("UPDATE {$CONFIG->dbprefix}annotations
		set value_id='$value', value_type='$value_type', access_id=$access_id, owner_guid=$owner_guid
		where id=$annotation_id and name_id='$name' and $access");

	if ($result !== false) {
		$obj = get_annotation($annotation_id);
		if (elgg_trigger_event('update', 'annotation', $obj)) {
			return true;
		} else {
			// @todo add plugin hook that sends old and new annotation information before db access
			delete_annotation($annotation_id);
		}
	}

	return $result;
}

/**
 * Returns annotations.  Accepts all elgg_get_entities() options for entity
 * restraints.
 *
 * @see elgg_get_entities
 *
 * @param array $options Array in format:
 *
 * 	annotation_names => NULL|ARR Annotation names
 *
 * 	annotation_values => NULL|ARR Annotation values
 *
 * 	annotation_case_sensitive => BOOL Overall Case sensitive
 *
 *  annotation_owner_guids => NULL|ARR guids for metadata owners
 *
 *  annotation_created_time_lower => INT Lower limit for created time.
 *
 *  annotation_created_time_upper => INT Upper limit for created time.
 *
 * @return array
 * @since 1.8.0
 */
function elgg_get_annotations($options = array()) {
	$defaults = array(
		// entities
		'types'					=>	ELGG_ENTITIES_ANY_VALUE,
		'subtypes'				=>	ELGG_ENTITIES_ANY_VALUE,
		'type_subtype_pairs'	=>	ELGG_ENTITIES_ANY_VALUE,

		'guids'					=>	ELGG_ENTITIES_ANY_VALUE,
		'owner_guids'			=>	ELGG_ENTITIES_ANY_VALUE,
		'container_guids'		=>	ELGG_ENTITIES_ANY_VALUE,
		'site_guids'			=>	get_config('site_guid'),

		'modified_time_lower'	=>	ELGG_ENTITIES_ANY_VALUE,
		'modified_time_upper'	=>	ELGG_ENTITIES_ANY_VALUE,
		'created_time_lower'	=>	ELGG_ENTITIES_ANY_VALUE,
		'created_time_upper'	=>	ELGG_ENTITIES_ANY_VALUE,

		// annotations
		// options are normalized to the plural in case we ever add support for them.
		'annotation_names'						=>	ELGG_ENTITIES_ANY_VALUE,
		'annotation_values'						=>	ELGG_ENTITIES_ANY_VALUE,
//		'annotation_name_value_pairs'			=>	ELGG_ENTITIES_ANY_VALUE,
//		'annotation_name_value_pairs_operator'	=>	'AND',

		'annotation_case_sensitive' 			=>	TRUE,
//		'order_by_annotation'					=>	array(),

		'annotation_created_time_lower'			=>	ELGG_ENTITIES_ANY_VALUE,
		'annotation_created_time_upper'			=>	ELGG_ENTITIES_ANY_VALUE,

		'annotation_owner_guids'				=>	ELGG_ENTITIES_ANY_VALUE,

		// sql
		'order_by'	=>	'a.time_created asc',
		'limit'		=>	10,
		'offset'	=>	0,
		'count'		=>	FALSE,
		'selects'	=>	array(),
		'wheres'	=>	array(),
		'joins'		=>	array(),

		'callback'	=> 'row_to_elggannotation',
	);

	$options = array_merge($defaults, $options);

	// can't use helper function with type_subtype_pair because
	// it's already an array...just need to merge it
	if (isset($options['type_subtype_pair'])) {
		if (isset($options['type_subtype_pairs'])) {
			$options['type_subtype_pairs'] = array_merge($options['type_subtype_pairs'],
				$options['type_subtype_pair']);
		} else {
			$options['type_subtype_pairs'] = $options['type_subtype_pair'];
		}
	}

	$singulars = array('type', 'subtype', 'guid', 'owner_guid', 'container_guid', 'site_guid',
						'annotation_name', 'annotation_value'
					);
	$options = elgg_normalise_plural_options_array($options, $singulars);

	if (!$options) {
		return false;
	}

	$db_prefix = elgg_get_config('dbprefix');

	// evaluate where clauses
	if (!is_array($options['wheres'])) {
		$options['wheres'] = array($options['wheres']);
	}

	$wheres = $options['wheres'];

	// entities
	$wheres[] = elgg_get_entity_type_subtype_where_sql('e', $options['types'],
		$options['subtypes'], $options['type_subtype_pairs']);

	$wheres[] = elgg_get_guid_based_where_sql('e.guid', $options['guids']);
	$wheres[] = elgg_get_guid_based_where_sql('e.owner_guid', $options['owner_guids']);
	$wheres[] = elgg_get_guid_based_where_sql('e.container_guid', $options['container_guids']);
	$wheres[] = elgg_get_guid_based_where_sql('e.site_guid', $options['site_guids']);

	$wheres[] = elgg_get_entity_time_where_sql('e', $options['created_time_upper'],
		$options['created_time_lower'], $options['modified_time_upper'], $options['modified_time_lower']);

	// annotations
	$annotation_clauses = elgg_get_annotation_sql('a', $options['annotation_names'],
		$options['annotation_values'], $options['annotation_case_sensitive']);

	$wheres = array_merge($wheres, $annotation_clauses['wheres']);

	$wheres[] = elgg_get_entity_time_where_sql('a', $options['annotation_created_time_upper'],
		$options['annotation_created_time_lower'], null, null);

	$wheres[] = elgg_get_guid_based_where_sql('a.owner_guid', $options['annotation_owner_guids']);

	// remove identical where clauses
	$wheres = array_unique($wheres);

	// see if any functions failed
	// remove empty strings on successful functions
	foreach ($wheres as $i => $where) {
		if ($where === FALSE) {
			return FALSE;
		} elseif (empty($where)) {
			unset($wheres[$i]);
		}
	}

	// evaluate join clauses
	if (!is_array($options['joins'])) {
		$options['joins'] = array($options['joins']);
	}

	$joins = $options['joins'];

	$joins = array_merge($joins, $annotation_clauses['joins']);
	$joins[] = "JOIN {$db_prefix}entities e ON a.entity_guid = e.guid";
	$joins[] = "JOIN {$db_prefix}metastrings n on a.name_id = n.id";
	$joins[] = "JOIN {$db_prefix}metastrings v on a.value_id = v.id";


	// remove identical join clauses
	$joins = array_unique($joins);

	foreach ($joins as $i => $join) {
		if ($join === FALSE) {
			return FALSE;
		} elseif (empty($join)) {
			unset($joins[$i]);
		}
	}

	// evalutate selects
	if ($options['selects']) {
		$selects = '';
		foreach ($options['selects'] as $select) {
			$selects .= ", $select";
		}
	} else {
		$selects = '';
	}

	// n_table is the normalized table that holds metastrings info.
	if (!$options['count']) {
		$query = "SELECT DISTINCT a.*, n.string as name, v.string as value FROM {$db_prefix}annotations a";
	} else {
		$query = "SELECT count(DISTINCT a.*) as total FROM {$db_prefix}annotations a";
	}

	// add joins
	foreach ($joins as $j) {
		$query .= " $j ";
	}

	// add wheres
	$query .= ' WHERE ';

	foreach ($wheres as $w) {
		$query .= " $w AND ";
	}

	// Add access controls
	$query .= get_access_sql_suffix('e');
	if (!$options['count']) {
		if ($options['group_by'] = sanitise_string($options['group_by'])) {
			$query .= " GROUP BY {$options['group_by']}";
		}

		if ($options['order_by'] = sanitise_string($options['order_by'])) {
			$query .= " ORDER BY {$options['order_by']}";
		}

		if ($options['limit']) {
			$limit = sanitise_int($options['limit']);
			$offset = sanitise_int($options['offset']);
			$query .= " LIMIT $offset, $limit";
		}

		$dt = get_data($query, $options['callback']);
		return $dt;
	} else {
		$total = get_data_row($query);
		return (int)$total->total;
	}
}

/**
 * Returns an array of joins and wheres for use in annotations.
 *
 * @note The $pairs is reserved for name/value pairs if we want to implement those.
 *
 * @param string $table          The annotation table name or alias
 * @param array  $names          An array of names
 * @param array  $values         An array of values
 * @param array  $pairs          Name / value pairs. Not currently used.
 * @param bool   $case_sensitive Should name and values be case sensitive?
 *
 * @return array
 */
function elgg_get_annotation_sql($table, $names = null, $values = null,
	$pairs = null, $case_sensitive = false) {

	if ((!$names && $names !== 0)
		&& (!$values && $values !== 0)
		&& (!$pairs && $pairs !== 0)) {

		return '';
	}

	$db_prefix = elgg_get_config('dbprefix');

	// join counter for incremental joins.
	$i = 1;

	// binary forces byte-to-byte comparision of strings, making
	// it case- and diacritical-mark- sensitive.
	// only supported on values.
	$binary = ($case_sensitive) ? ' BINARY ' : '';

	$access = get_access_sql_suffix($table);

	$return = array (
		'joins' => array (),
		'wheres' => array()
	);

	$wheres = array();

	// get names wheres and joins
	$names_where = '';
	if ($names !== NULL) {
		if (!is_array($names)) {
			$names = array($names);
		}

		$sanitised_names = array();
		foreach ($names as $name) {
			// normalise to 0.
			if (!$name) {
				$name = '0';
			}
			$sanitised_names[] = '\'' . sanitise_string($name) . '\'';
		}

		if ($names_str = implode(',', $sanitised_names)) {
			$return['joins'][] = "JOIN {$db_prefix}metastrings msn on $table.name_id = msn.id";
			$names_where = "(msn.string IN ($names_str))";
		}
	}

	// get values wheres and joins
	$values_where = '';
	if ($values !== NULL) {
		if (!is_array($values)) {
			$values = array($values);
		}

		$sanitised_values = array();
		foreach ($values as $value) {
			// normalize to 0
			if (!$value) {
				$value = 0;
			}
			$sanitised_values[] = '\'' . sanitise_string($value) . '\'';
		}

		if ($values_str = implode(',', $sanitised_values)) {
			$return['joins'][] = "JOIN {$db_prefix}metastrings msv on $table.value_id = msv.id";
			$values_where = "({$binary}msv.string IN ($values_str))";
		}
	}

	if ($names_where && $values_where) {
		$wheres[] = "($names_where AND $values_where AND $access)";
	} elseif ($names_where) {
		$wheres[] = "($names_where AND $access)";
	} elseif ($values_where) {
		$wheres[] = "($values_where AND $access)";
	}

	if ($where = implode(' AND ', $wheres)) {
		$return['wheres'][] = "($where)";
	}

	return $return;
}

/**
 * Get a list of annotations for a given object/user/annotation type.
 *
 * @param int|array $entity_guid       GUID to return annotations of (falsey for any)
 * @param string    $entity_type       Type of entity
 * @param string    $entity_subtype    Subtype of entity
 * @param string    $name              Name of annotation
 * @param mixed     $value             Value of annotation
 * @param int|array $owner_guid        Owner(s) of annotation
 * @param int       $limit             Limit
 * @param int       $offset            Offset
 * @param string    $order_by          Order annotations by SQL
 * @param int       $timelower         Lower time limit
 * @param int       $timeupper         Upper time limit
 * @param int       $entity_owner_guid Owner guid for the entity
 *
 * @return array
 */
function get_annotations($entity_guid = 0, $entity_type = "", $entity_subtype = "", $name = "",
$value = "", $owner_guid = 0, $limit = 10, $offset = 0, $order_by = "asc", $timelower = 0,
$timeupper = 0, $entity_owner_guid = 0) {
	global $CONFIG;

	$options = array();

	if ($entity_guid) {
		$options['guid'] = $entity_guid;
	}

	if ($entity_type) {
		$options['type'] = $entity_type;
	}

	if ($entity_subtype) {
		$options['subtype'] = $entity_subtype;
	}

	if ($name) {
		$options['annotation_name'] = $name;
	}

	if ($value) {
		$options['annotation_value'] = $value;
	}

	if ($owner_guid) {
		$options['annotation_owner_guid'] = $owner_guid;
	}

	$options['limit'] = $limit;
	$options['offset'] = $offset;

	if ($order_by == 'desc') {
		$options['order_by'] = 'a.time_created desc';
	}

	if ($timelower) {
		$options['annotation_time_lower'] = $timelower;
	}

	if ($timeupper) {
		$options['annotation_time_upper'] = $timeupper;
	}

	if ($entity_owner_guid) {
		$options['owner_guid'] = $entity_owner_guid;
	}

	return elgg_get_annotations($options);
}

/**
 * Returns entities based upon annotations.  Accepts the same values as
 * elgg_get_entities_from_metadata() but uses the annotations table.
 *
 * NB: Entity creation time is selected as max_time. To sort based upon
 * this, pass 'order_by' => 'maxtime asc' || 'maxtime desc'
 *
 * time_created in this case will be the time the annotation was created.
 *
 * @see elgg_get_entities
 * @see elgg_get_entities_from_metadata
 *
 * @param array $options Array in format:
 *
 * 	annotation_names => NULL|ARR annotations names
 *
 * 	annotation_values => NULL|ARR annotations values
 *
 * 	annotation_name_value_pairs => NULL|ARR (name = 'name', value => 'value',
 * 	'operator' => '=', 'case_sensitive' => TRUE) entries.
 * 	Currently if multiple values are sent via an array (value => array('value1', 'value2')
 * 	the pair's operator will be forced to "IN".
 *
 * 	annotation_name_value_pairs_operator => NULL|STR The operator to use for combining
 *  (name = value) OPERATOR (name = value); default AND
 *
 * 	annotation_case_sensitive => BOOL Overall Case sensitive
 *
 *  order_by_annotation => NULL|ARR (array('name' => 'annotation_text1', 'direction' => ASC|DESC,
 *  'as' => text|integer),
 *
 *  Also supports array('name' => 'annotation_text1')
 *
 *  annotation_owner_guids => NULL|ARR guids for annotaiton owners
 *
 * @return array
 * @since 1.7.0
 */
function elgg_get_entities_from_annotations(array $options = array()) {
	$defaults = array(
		'annotation_names'						=>	ELGG_ENTITIES_ANY_VALUE,
		'annotation_values'						=>	ELGG_ENTITIES_ANY_VALUE,
		'annotation_name_value_pairs'			=>	ELGG_ENTITIES_ANY_VALUE,

		'annotation_name_value_pairs_operator'	=>	'AND',
		'annotation_case_sensitive' 			=>	TRUE,
		'order_by_annotation'					=>	array(),

		'annotation_created_time_lower'			=>	ELGG_ENTITIES_ANY_VALUE,
		'annotation_created_time_upper'			=>	ELGG_ENTITIES_ANY_VALUE,

		'annotation_owner_guids'				=>	ELGG_ENTITIES_ANY_VALUE,

		'order_by'								=>	'maxtime desc',
		'group_by'								=>	'a.entity_guid'
	);

	$options = array_merge($defaults, $options);

	$singulars = array('annotation_name', 'annotation_value',
	'annotation_name_value_pair', 'annotation_owner_guid');

	$options = elgg_normalise_plural_options_array($options, $singulars);

	if (!$options = elgg_entities_get_metastrings_options('annotation', $options)) {
		return FALSE;
	}

	// special sorting for annotations
	//@todo overrides other sorting
	$options['selects'][] = "max(n_table.time_created) as maxtime";
	$options['group_by'] = 'n_table.entity_guid';

	$time_wheres = elgg_get_entity_time_where_sql('a', $options['annotation_created_time_upper'],
		$options['annotation_created_time_lower']);

	if ($time_wheres) {
		$options['wheres'] = array_merge($options['wheres'], $time_wheres);
	}

	return elgg_get_entities_from_metadata($options);
}

/**
 * Returns a viewable list of entities from annotations.
 *
 * @param array $options Options array
 *
 * @see elgg_get_entities_from_annotations()
 * @see elgg_list_entities()
 *
 * @return str
 */
function elgg_list_entities_from_annotations($options = array()) {
	return elgg_list_entities($options, 'elgg_get_entities_from_annotations');
}

/**
 * Returns a human-readable list of annotations on a particular entity.
 *
 * @param int        $entity_guid The entity GUID
 * @param string     $name        The name of the kind of annotation
 * @param int        $limit       The number of annotations to display at once
 * @param true|false $asc         Display annotations in ascending order. (Default: true)
 *
 * @return string HTML (etc) version of the annotation list
 */
function list_annotations($entity_guid, $name = "", $limit = 25, $asc = true) {
	if ($asc) {
		$asc = "asc";
	} else {
		$asc = "desc";
	}
	$count = count_annotations($entity_guid, "", "", $name);
	$offset = (int) get_input("annoff", 0);
	$annotations = get_annotations($entity_guid, "", "", $name, "", "", $limit, $offset, $asc);

	$params = array(
		'count'  => $count,
		'offset' => $offset,
		'limit'  => $count,
	);
	return elgg_view_annotation_list($annotations, $params);
}

/**
 * Return the sum of a given integer annotation.
 *
 * @param int    $entity_guid    Guid of Entity
 * @param string $entity_type    Type of Entity
 * @param string $entity_subtype Subtype of Entity
 * @param string $name           Name of annotation
 * @param string $value          Value of annotation
 * @param string $value_type     Type of value
 * @param int    $owner_guid     GUID of owner of annotation
 *
 * @return int
 */
function get_annotations_sum($entity_guid, $entity_type = "", $entity_subtype = "", $name = "",
$value = "", $value_type = "", $owner_guid = 0) {

	return get_annotations_calculate_x("sum", $entity_guid, $entity_type, $entity_subtype, $name,
	$value, $value_type, $owner_guid);
}

/**
 * Return the max of a given integer annotation.
 *
 * @param int    $entity_guid    Guid of Entity
 * @param string $entity_type    Type of Entity
 * @param string $entity_subtype Subtype of Entity
 * @param string $name           Name of annotation
 * @param string $value          Value of annotation
 * @param string $value_type     Type of value
 * @param int    $owner_guid     GUID of owner of annotation
 *
 * @return int
 */
function get_annotations_max($entity_guid, $entity_type = "", $entity_subtype = "", $name = "",
$value = "", $value_type = "", $owner_guid = 0) {

	return get_annotations_calculate_x("max", $entity_guid, $entity_type, $entity_subtype, $name,
	$value, $value_type, $owner_guid);
}

/**
 * Return the minumum of a given integer annotation.
 *
 * @param int    $entity_guid    Guid of Entity
 * @param string $entity_type    Type of Entity
 * @param string $entity_subtype Subtype of Entity
 * @param string $name           Name of annotation
 * @param string $value          Value of annotation
 * @param string $value_type     Type of value
 * @param int    $owner_guid     GUID of owner of annotation
 *
 * @return int
 */
function get_annotations_min($entity_guid, $entity_type = "", $entity_subtype = "", $name = "",
$value = "", $value_type = "", $owner_guid = 0) {

	return get_annotations_calculate_x("min", $entity_guid, $entity_type, $entity_subtype, $name,
	$value, $value_type, $owner_guid);
}

/**
 * Return the average of a given integer annotation.
 *
 * @param int    $entity_guid    Guid of Entity
 * @param string $entity_type    Type of Entity
 * @param string $entity_subtype Subtype of Entity
 * @param string $name           Name of annotation
 * @param string $value          Value of annotation
 * @param string $value_type     Type of value
 * @param int    $owner_guid     GUID of owner of annotation
 *
 * @return int
 */
function get_annotations_avg($entity_guid, $entity_type = "", $entity_subtype = "", $name = "",
$value = "", $value_type = "", $owner_guid = 0) {

	return get_annotations_calculate_x("avg", $entity_guid, $entity_type, $entity_subtype, $name,
	$value, $value_type, $owner_guid);
}

/**
 * Count the number of annotations based on search parameters
 *
 * @param int    $entity_guid    Guid of Entity
 * @param string $entity_type    Type of Entity
 * @param string $entity_subtype Subtype of Entity
 * @param string $name           Name of annotation
 * @param string $value          Value of annotation
 * @param string $value_type     Type of value
 * @param int    $owner_guid     GUID of owner of annotation
 * @param int    $timelower      Lower time limit
 * @param int    $timeupper      Upper time limit
 *
 * @return int
 */
function count_annotations($entity_guid = 0, $entity_type = "", $entity_subtype = "",
$name = "", $value = "", $value_type = "", $owner_guid = 0, $timelower = 0,
$timeupper = 0) {
	return get_annotations_calculate_x("count", $entity_guid, $entity_type, $entity_subtype,
		$name, $value, $value_type, $owner_guid, $timelower, $timeupper);
}

/**
 * Perform a mathmatical calculation on integer annotations.
 *
 * @param string $sum            What sort of calculation to perform
 * @param int    $entity_guid    Guid of Entity
 * @param string $entity_type    Type of Entity
 * @param string $entity_subtype Subtype of Entity
 * @param string $name           Name of annotation
 * @param string $value          Value of annotation
 * @param string $value_type     Type of value
 * @param int    $owner_guid     GUID of owner of annotation
 * @param int    $timelower      Lower time limit
 * @param int    $timeupper      Upper time limit
 *
 * @return int
 */
function get_annotations_calculate_x($sum = "avg", $entity_guid, $entity_type = "",
$entity_subtype = "", $name = "", $value = "", $value_type = "", $owner_guid = 0,
$timelower = 0, $timeupper = 0) {
	global $CONFIG;

	$sum = sanitise_string($sum);
	$entity_guid = (int)$entity_guid;
	$entity_type = sanitise_string($entity_type);
	$timeupper = (int)$timeupper;
	$timelower = (int)$timelower;

	if ($entity_subtype) {
		if (!$entity_subtype = get_subtype_id($entity_type, $entity_subtype)) {
			// requesting a non-existing subtype: return false
			return FALSE;
		}
	}

	if ($name != '' AND !$name = get_metastring_id($name)) {
		return 0;
	}

	if ($value != '' AND !$value = get_metastring_id($value)) {
		return 0;
	}
	$value_type = sanitise_string($value_type);
	$owner_guid = (int)$owner_guid;

	// if (empty($name)) return 0;

	$where = array();

	if ($entity_guid) {
		$where[] = "e.guid=$entity_guid";
	}

	if ($entity_type != "") {
		$where[] = "e.type='$entity_type'";
	}

	if ($entity_subtype) {
		$where[] = "e.subtype=$entity_subtype";
	}

	if ($name != "") {
		$where[] = "a.name_id='$name'";
	}

	if ($value != "") {
		$where[] = "a.value_id='$value'";
	}

	if ($value_type != "") {
		$where[] = "a.value_type='$value_type'";
	}

	if ($owner_guid) {
		$where[] = "a.owner_guid='$owner_guid'";
	}

	if ($timelower) {
		$where[] = "a.time_created >= {$timelower}";
	}

	if ($timeupper) {
		$where[] = "a.time_created <= {$timeupper}";
	}

	if ($sum != "count") {
		$where[] = "a.value_type='integer'"; // Limit on integer types
	}

	$query = "SELECT $sum(ms.string) as sum
		FROM {$CONFIG->dbprefix}annotations a
		JOIN {$CONFIG->dbprefix}entities e on a.entity_guid = e.guid
		JOIN {$CONFIG->dbprefix}metastrings ms on a.value_id=ms.id WHERE ";

	foreach ($where as $w) {
		$query .= " $w and ";
	}

	$query .= get_access_sql_suffix("a"); // now add access
	$query .= ' and ' . get_access_sql_suffix("e"); // now add access

	$row = get_data_row($query);
	if ($row) {
		return $row->sum;
	}

	return false;
}

/**
 * Get entities ordered by a mathematical calculation
 *
 * @param array $options An options array:
 * 	'calculation' => The calculation to use. Must be a valid MySQL function.
 *                   Defaults to sum.  Result selected as 'calculated'.
 *	'order_by'    => The order for the sorting. Defaults to 'calculated desc'.
 *
 * @return mixed
 */
function elgg_get_entities_from_annotation_calculation($options) {
	global $CONFIG;

	$defaults = array(
		'calculation'	=>	'sum',
		'order_by'		=>	'calculated desc',
	);

	$options = array_merge($defaults, $options);

	$function = sanitize_string(elgg_get_array_value('calculation', $options, 'sum', false));

	// you must cast this as an int or it sorts wrong.
	$options['selects'][] = "$function(cast(msv.string as signed)) as calculated";
	$options['selects'][] = "msn.string as value";
	$options['order_by'] = 'calculated desc';

	// need our own join to get the values.
	$db_prefix = get_config('dbprefix');
	$options['joins'][] = "JOIN {$db_prefix}annotations calc_table on e.guid = calc_table.entity_guid";
	$options['joins'][] = "JOIN {$db_prefix}metastrings msv on calc_table.value_id = msv.id";
	$options['wheres'][] = "calc_table.name_id = n_table.name_id";

	return elgg_get_entities_from_annotations($options);
}

/**
 * List entities from an annotation calculation.
 *
 * @see elgg_get_entities_from_annotation_calculation()
 *
 * @param array $options An options array.
 *
 * @return string
 */
function elgg_list_entities_from_annotation_calculation($options) {
	return elgg_list_entities($options, 'elgg_get_entities_from_annotation_calculation');
}

/**
 * Lists entities by the totals of a particular kind of annotation AND
 * the value of a piece of metadata
 *
 * @param string  $entity_type    Type of entity.
 * @param string  $entity_subtype Subtype of entity.
 * @param string  $name           Name of annotation.
 * @param string  $mdname         Metadata name
 * @param string  $mdvalue        Metadata value
 * @param int     $limit          Maximum number of results to return.
 * @param int     $owner_guid     Owner.
 * @param int     $group_guid     Group container. Currently only supported if entity_type is object
 * @param boolean $asc            Whether to list in ascending or descending order (default: desc)
 * @param boolean $fullview       Whether to display the entities in full
 * @param boolean $listtypetoggle Can the 'gallery' view can be displayed (default: no)
 * @param boolean $pagination     Display pagination
 * @param string  $orderdir       'desc' or 'asc'
 *
 * @return string Formatted entity list
 */
function list_entities_from_annotation_count_by_metadata($entity_type = "", $entity_subtype = "",
$name = "", $mdname = '', $mdvalue = '', $limit = 10, $owner_guid = 0, $group_guid = 0,
$asc = false, $fullview = true, $listtypetoggle = false, $pagination = true, $orderdir = 'desc') {

	$msg = 'list_entities_from_annotation_count_by_metadata() is deprecated by elgg_list_entities_from_annotation_calculation().';

	elgg_deprecated_notice($msg, 1.8);

	$options = array();

	$options['calculation'] = 'sum';

	if ($entity_type) {
		$options['types'] = $entity_type;
	}

	if ($entity_subtype) {
		$options['subtypes'] = $entity_subtype;
	}

	$options['annotation_names'] = $name;

	if ($mdname) {
		$options['metadata_name'] = $mdname;
	}

	if ($mdvalue) {
		$options['metadata_value'] = $mdvalue;
	}

	if ($owner_guid) {
		if (is_array($owner_guid)) {
			$options['owner_guids'] = $owner_guid;
		} else {
			$options['owner_guid'] = $owner_guid;
		}
	}

	$options['full_view'] = $fullview;

	$options['list_type_toggle'] = $listtypetoggle;

	$options['pagination'] = $pagination;

	$options['limit'] = $limit;

	$options['order_by'] = "calculated $orderdir";

	return elgg_get_entities_from_annotation_calculation($options);
}

/**
 * Delete a given annotation.
 *
 * @param int $id The annotation id
 *
 * @return bool
 */
function delete_annotation($id) {
	global $CONFIG;

	$id = (int)$id;

	$access = get_access_sql_suffix();
	$annotation = get_annotation($id);

	if (elgg_trigger_event('delete', 'annotation', $annotation)) {
		remove_from_river_by_annotation($id);
		return delete_data("DELETE from {$CONFIG->dbprefix}annotations where id=$id and $access");
	}

	return FALSE;
}

/**
 * Clear all the annotations for a given entity, assuming you have access to that metadata.
 *
 * @param int    $guid The entity guid
 * @param string $name The name of the annotation to delete.
 *
 * @return int Number of annotations deleted or false if an error
 */
function clear_annotations($guid, $name = "") {
	global $CONFIG;

	$guid = (int)$guid;

	if (!empty($name)) {
		$name = get_metastring_id($name);
		if ($name === false) {
			// name doesn't exist so 0 rows were deleted
			return 0;
		}
	}

	$entity_guid = (int) $guid;
	if ($entity = get_entity($entity_guid)) {
		if ($entity->canEdit()) {
			$where = array();

			if ($name != "") {
				$where[] = " name_id='$name'";
			}

			$query = "DELETE from {$CONFIG->dbprefix}annotations where entity_guid=$guid ";
			foreach ($where as $w) {
				$query .= " and $w";
			}

			return delete_data($query);
		}
	}

	return FALSE;
}

/**
 * Clear all annotations belonging to a given owner_guid
 *
 * @param int $owner_guid The owner
 *
 * @return int Number of annotations deleted
 */
function clear_annotations_by_owner($owner_guid) {
	global $CONFIG;

	$owner_guid = (int)$owner_guid;

	$query = "SELECT id from {$CONFIG->dbprefix}annotations WHERE owner_guid=$owner_guid";

	$annotations = get_data($query);
	$deleted = 0;

	if (!$annotations) {
		return 0;
	}

	foreach ($annotations as $id) {
		// Is this the best way?
		if (delete_annotation($id->id)) {
			$deleted++;
		}
	}

	return $deleted;
}

/**
 * Handler called by trigger_plugin_hook on the "export" event.
 *
 * @param string $hook        'export'
 * @param string $entity_type 'all'
 * @param mixed  $returnvalue Default return value
 * @param mixed  $params      List of params to export
 *
 * @elgg_plugin_hook export all
 *
 * @return mixed
 */
function export_annotation_plugin_hook($hook, $entity_type, $returnvalue, $params) {
	// Sanity check values
	if ((!is_array($params)) && (!isset($params['guid']))) {
		throw new InvalidParameterException(elgg_echo('InvalidParameterException:GUIDNotForExport'));
	}

	if (!is_array($returnvalue)) {
		throw new InvalidParameterException(elgg_echo('InvalidParameterException:NonArrayReturnValue'));
	}

	$guid = (int)$params['guid'];
	$name = $params['name'];

	$result = get_annotations($guid);

	if ($result) {
		foreach ($result as $r) {
			$returnvalue[] = $r->export();
		}
	}

	return $returnvalue;
}

/**
 * Get the URL for this item of metadata, by default this links to the
 * export handler in the current view.
 *
 * @param int $id Annotation id
 *
 * @return mixed
 */
function get_annotation_url($id) {
	$id = (int)$id;

	if ($extender = get_annotation($id)) {
		return get_extender_url($extender);
	}
	return false;
}

/**
 * Check to see if a user has already created an annotation on an object
 *
 * @param int    $entity_guid     Entity guid
 * @param string $annotation_type Type of annotation
 * @param int    $owner_guid      Defaults to logged in user.
 *
 * @return bool
 * @since 1.8.0
 */
function elgg_annotation_exists($entity_guid, $annotation_type, $owner_guid = NULL) {
	global $CONFIG;

	if (!$owner_guid && !($owner_guid = elgg_get_logged_in_user_guid())) {
		return FALSE;
	}

	$entity_guid = (int)$entity_guid;
	$annotation_type = sanitise_string($annotation_type);

	$sql = "select a.id" .
			" FROM {$CONFIG->dbprefix}annotations a, {$CONFIG->dbprefix}metastrings m " .
			" WHERE a.owner_guid={$owner_guid} AND a.entity_guid={$entity_guid} " .
			" AND a.name_id=m.id AND m.string='{$annotation_type}'";

	if ($check_annotation = get_data_row($sql)) {
		return TRUE;
	}

	return FALSE;
}

/**
 * Register an annotation url handler.
 *
 * @param string $function_name The function.
 * @param string $extender_name The name, default 'all'.
 *
 * @return string
 */
function register_annotation_url_handler($function_name, $extender_name = "all") {
	return register_extender_url_handler($function_name, 'annotation', $extender_name);
}

/** Register the hook */
elgg_register_plugin_hook_handler("export", "all", "export_annotation_plugin_hook", 2);
