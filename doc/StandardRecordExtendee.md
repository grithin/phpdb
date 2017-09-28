See doc/Record.md in phpbase

# Use
```php
/*
create table test (
 id int auto_increment,
 name varchar(20),
 details__json text,
 primary key (id)
)
*/

class Test2 extends \Grithin\StandardRecordExtendee{
	static $table = 'test';
	public $json_mapped_columns = ["details"];
	public function __construct($identifier, $options=[]){
		if(!$options['db']){
			$options = array_merge($options, ['db'=>Db::primary()]);
		}
		return parent::__construct($identifier, $options);
	}
}


# create record from raw data
$raw_record = [
	'name' => 'bobaa',
	'details__json' => json_encode(['time'=>'now'])
];
$record = Test2::create_from_initial($raw_record);
$record['details']['time'] = 'later';
$record['name'] = 'sue';
$record->apply();




# create record from transformed
$new_record = [
	'name' => 'bob2',
	'details' => ['time'=>'now']
];
$record = Test2::create_from_transformed($new_record);
$record['name'] .= '3';
$record->apply();




```


__Additional Transformers__
```php
# ensure `name` column starts with `bob`, but does not show up in script data
class Test extends \Grithin\StandardRecordExtendee{
	public function tranformer_on_get($row){
		$row['name'] = substr($row['name'], 3);
		return $this->json_transform_on_get($row);
	}
	public function tranformer_on_set($changes){
		$changes = $this->json_transform_on_set($changes);

		if(array_key_exists('name', $changes)){
			$changes['name'] = 'bob'.$changes['name'];
		}
		return $changes;
	}
```
