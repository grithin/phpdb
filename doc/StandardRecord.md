# Use
```php
# create a record
$record = StandardRecord::create('test', ['name'=>'bob', 'details__json'=>json_encode(['time'=>'now'])], ['json_mapped_columns'=>true]);
$record['details']['time'] = 'later';
$record->apply();

# pull and update a record
$record = new StandardRecord(1, 'test', ['json_mapped_columns'=>true]);
$record['details']['time'] = 'even later';
$record->apply();

# preload a record from array
$record = new StandardRecord(1,	'test',
		[
			'json_mapped_columns'=>true,
			'initial_record'=>[
				'id'=>1,
				'name' => 'bob',
				'details__json'=>json_encode(['time'=>'even later'])
]]);
$record['name'] = 'sue';
$record->apply();
```
