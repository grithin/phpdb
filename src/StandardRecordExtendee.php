<?php
/* About
For extending StandardRecordAbstract
*/

namespace Grithin;

use \Exception;

abstract class StandardRecordExtendee extends StandardRecordAbstract{
	static $table;
	static $id_column = 'id';
	public $transformers = ['get'=>null, 'set'=>null];
	public $json_mapped_columns = [];
	# see Grithin\Record for other option values
	/*	params
		options; {
			db: < db object >
			initial_record: < preloaded record >
		}
	*/
	public function __construct($identifier, $options=[]){
		$this->db = $options['db'] ? $options['db'] : \Grithin\Db::primary();

		if(!static::$table){
			throw new Exception('Must provide table');
		}
		$this->table = static::$table;

		#+ handle record transformer options {
		if(method_exists($this, 'tranformer_on_get')){
			$this->transformers['get'] = [$this, 'tranformer_on_get'];
		}
		if(method_exists($this, 'tranformer_on_set')){
			$this->transformers['set'] = [$this, 'tranformer_on_set'];
		}
		if($this->json_mapped_columns){
			if(!$this->transformers['get']){
				$this->transformers['get'] = [$this, 'json_transform_on_get'];
			}
			if(!$this->transformers['set']){
				$this->transformers['set'] = [$this, 'json_transform_on_set'];
			}
		}
		#+ }


		# transform initial_record, as thought it were from the database
		if($options['initial_record'] && $this->transformers['get']){
			$options['initial_record'] = $this->transformers['get']($options['initial_record']);
		}elseif(!empty($options['transformed_initial_record'])){ # bypass get transformation
			$options['initial_record'] = $options['transformed_initial_record'];
		}

		# identifier is an id, use the id column (which might not be named `id`)
		if(Tool::isInt($identifier)){
			$identifier = [static::$id_column => $identifier];
		}

		parent::__construct($identifier, [$this, 'getter'], [$this, 'setter'], $options);
	}

	static function pseudo_get(){
		return static::pseudo_from_initial([]);
	}
	static function pseudo_from_initial($initial_record=[]){
		return static::from_initial($initial_record, false, ['db'=>true]);
	}
	static function pseudo_from_transformed($transformed_record=[]){
		return static::from_transformed($transformed_record, false, ['db'=>true]);
	}
	static function from_initial($initial_record=[], $identifier=null, $options=[]){
		$options = array_merge($options, ['initial_record'=>$initial_record]);
		if($identifier === null){ # identifier as false indicates not to set it.  As null, indicates should pull from record
			$identifier = [static::$id_column => $initial_record[static::$id_column]];
		}
		return new static($identifier, $options);
	}
	static function from_transformed($transformed_record=[], $identifier=null, $options=[]){
		$options = array_merge($options, ['transformed_initial_record'=>$transformed_record]);
		if($identifier === null){ # identifier as false indicates not to set it.  As null, indicates should pull from record
			$id_column = static::transform_on_get_apply_to_key(static::$id_column);
			$identifier = [static::$id_column => $transformed_record[$id_column]];
		}
		return new static($identifier, $options);
	}
	static function transform_on_get_apply($row){
		return static::pseudo_get()->transformers['get']($row);
	}
	static function transform_on_set_apply($row){
		return static::pseudo_get()->transformers['set']($row);
	}
	# apply transformation to just the key name
	static function transform_on_get_apply_to_key($key){
		return array_keys(static::transform_on_get_apply([$key=>null]))[0];
	}
	# apply transformation to just the key name
	static function transform_on_set_apply_to_key($key){
		return array_keys(static::transform_on_set_apply([$key=>null]))[0];
	}

	/*
	Allow for variable input to be transformed into a record, using the cases of:
	-	already a record, return it
	-	a scalar, use as identifier
	-	an array without the id_column, use as identifier
	-	an array with the id column
		-	if include other keys, use as initial record value
		-	if only one key, use as identifier
	*/
	static function construct_from($thing){
		if(\Grithin\Tool::is_scalar($thing)){
			return new static([static::$id_column=>$thing]); # identifier value
		}else{
			if(is_array($thing)){
				if(array_key_exists(static::$id_column, $thing)){
					if(count($thing) == 1){
						return new static($thing); # identifier array
					}else{
						return new static([static::$id_column=>$thing[static::$id_column]], ['initial_record'=>$thing]); # initial record
					}
				}else{
					return new static($thing); # identifier array
				}
			}elseif($thing instanceof static){
				return $thing;
			}else{
				throw new Exception('Could not construct from unknown thing');
			}
		}
	}


	static function create_from_initial($record, $options=[]){
		$options['db'] = $options['db'] ? $options['db'] : \Grithin\Db::primary();
		$id = $options['db']->insert(static::$table, $record);
		$new_key = [static::$id_column=>$id];
		$record = array_replace($record, $new_key);
		return static::from_initial($record, $new_key, $options);
	}
	static function create_from_transformed($record, $options=[]){
		$options['db'] = $options['db'] ? $options['db'] : \Grithin\Db::primary();
		$record = static::pseudo_from_transformed($record)->record_untransformed();
		$id = $options['db']->insert(static::$table, $record);
		$new_key = [static::$id_column=>$id];
		$record = array_replace($record, $new_key);
		return static::from_initial($record, $new_key, $options);
	}

	# utility function to create a new record and return it as this class
	/* params
	@record:	[]< raw, untransformed record (json fields must be text) >
	*/
	static function static_creates($record, $options=[]){
		$db = $options['db'] = $options['db'] ? $options['db'] : \Grithin\Db::primary();
		$id = $db->insert(static::$table, $record);
		$record[static::$id_column] = $id;
		$options['db'] = $db;
		$options['initial_record'] = $record;
		return new static([static::$id_column => $id], $options);
	}

	static function static_create_from_transformed(){

	}

	static function json_columns_extract_from_db($db){
		return self::json_columns_extract_from_db_by_table_and_db(static::$table, $db);
	}
}
