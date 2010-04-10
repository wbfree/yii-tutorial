<?php
/**
 * CSort class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2010 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

/**
 * CSort represents information relevant to sorting.
 *
 * When data needs to be sorted according to one or several attributes,
 * we can use CSort to represent the sorting information and generate
 * appropriate hyperlinks that can lead to sort actions.
 *
 * CSort is designed to be used together with {@link CActiveRecord}.
 * When creating a CSort instance, you need to specify {@link modelClass}.
 * You can use CSort to generate hyperlinks by calling {@link link}.
 * You can also use CSort to modify a {@link CDbCriteria} instance by calling {@link applyOrder} so that
 * it can cause the query results to be sorted according to the specified
 * attributes.
 *
 * In order to prevent SQL injection attacks, CSort ensures that only valid model attributes
 * can be sorted. This is determined based on {@link modelClass} and {@link attributes}.
 * When {@link attributes} is not set, all attributes belonging to {@link modelClass}
 * can be sorted. When {@link attributes} is set, only those attributes declared in the property
 * can be sorted.
 *
 * By configuring {@link attributes}, one can perform more complex sorts that may
 * consist of things like compound attributes (e.g. sort based on the combination of
 * first name and last name of users).
 *
 * The property {@link attributes} should be an array of key-value pairs, where the keys
 * represent the attribute names, while the values represent the virtual attribute definitions.
 * For more details, please check the documentation about {@link attributes}.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id$
 * @package system.web
 * @since 1.0.1
 */
class CSort extends CComponent
{
	/**
	 * @var boolean whether the sorting can be applied to multiple attributes simultaneously.
	 * Defaults to false, which means each time the data can only be sorted by one attribute.
	 */
	public $multiSort=false;
	/**
	 * @var string the name of the model class whose attributes can be sorted.
	 * The model class must be a child class of {@link CActiveRecord}.
	 */
	public $modelClass;
	/**
	 * @var array list of attributes that are allowed to be sorted.
	 * For example, array('user_id','create_time') would specify that only 'user_id'
	 * and 'create_time' of the model {@link modelClass} can be sorted.
	 * By default, this property is an empty array, which means all attributes in
	 * {@link modelClass} are allowed to be sorted.
	 *
	 * This property can also be used to specify complex sorting. To do so,
	 * a virtual attribute can be declared in terms of a key-value pair in the array.
	 * The key refers to the name of the virtual attribute that may appear in the sort request,
	 * while the value specifies the definition of the virtual attribute.
	 *
	 * In the simple case, a key-value pair can be like <code>'user'=>'user_id'</code>
	 * where 'user' is the name of the virtual attribute while 'user_id' means the virtual
	 * attribute is the 'user_id' attribute in the {@link modelClass}.
	 *
	 * A more flexible way is to specify the key-value pair as
	 * <pre>
	 * 'user'=>array(
	 *     'asc'=>'first_name, last_name',
	 *     'desc'=>'first_name DESC, last_name DESC',
	 *     'label'=>'Name'
	 * )
	 * </pre>
	 * where 'user' is the name of the virtual attribute that specifies the full name of user
	 * (a compound attribute consisting of first name and last name of user). In this case,
	 * we have to use an array to define the virtual attribute with three elements: 'asc',
	 * 'desc' and 'label'.
	 *
	 * The above approach can also be used to declare virtual attributes that consist of relational
	 * attributes. For example,
	 * <pre>
	 * 'price'=>array(
	 *     'asc'=>'item.price',
	 *     'desc'=>'item.price DESC',
	 *     'label'=>'Item Price'
	 * )
	 * </pre>
	 *
	 * Note, the attribute name should not contain '-' or '.' characters because
	 * they are used as {@link separators}.
	 */
	public $attributes=array();
	/**
	 * @var string the name of the GET parameter that specifies which attributes to be sorted
	 * in which direction. Defaults to 'sort'.
	 */
	public $sortVar='sort';
	/**
	 * @var string the tag appeared in the GET parameter that indicates the attribute should be sorted
	 * in descending order. Defaults to 'desc'.
	 */
	public $descTag='desc';
	/**
	 * @var string the default order that should be applied to the query criteria when
	 * the current request does not specify any sort. For example, 'create_time DESC', or
	 * 'name, create_time DESC'.
	 */
	public $defaultOrder;
	/**
	 * @var string the route (controller ID and action ID) for generating the sorted contents.
	 * Defaults to empty string, meaning using the currently requested route.
	 */
	public $route='';
	/**
	 * @var array separators used in the generated URL. This must be an array consisting of
	 * two elements. The first element specifies the character separating different
	 * attributes, while the second element specifies the character separating attribute name
	 * and the corresponding sort direction. Defaults to array('-','.').
	 */
	public $separators=array('-','.');
	/**
	 * @var array the additional GET parameters (name=>value) that should be used when generating sort URLs.
	 * Defaults to null, meaning using the currently available GET parameters.
	 * @since 1.0.9
	 */
	public $params;

	private $_directions;

	/**
	 * Constructor.
	 * @param string the class name of data models that need to be sorted.
	 * This should be a child class of {@link CActiveRecord}.
	 */
	public function __construct($modelClass=null)
	{
		$this->modelClass=$modelClass;
	}

	/**
	 * Modifies the query criteria by changing its {@link CDbCriteria::order} property.
	 * This method will use {@link directions} to determine which columns need to be sorted.
	 * They will be put in the ORDER BY clause. If the criteria already has non-empty {@link CDbCriteria::order} value,
	 * the new value will be appended to it.
	 * @param CDbCriteria the query criteria
	 */
	public function applyOrder($criteria)
	{
		$order=$this->getOrderBy();
		if(!empty($order))
		{
			if(!empty($criteria->order))
				$criteria->order.=', ';
			$criteria->order.=$order;
		}
	}

	/**
	 * @return string the order-by columns represented by this sort object.
	 * This can be put in the ORDER BY clause of a SQL statement.
	 * @since 1.1.0
	 */
	public function getOrderBy()
	{
		$directions=$this->getDirections();
		if(empty($directions))
			return $this->defaultOrder;
		else
		{
			if($this->modelClass!==null)
				$schema=CActiveRecord::model($this->modelClass)->getDbConnection()->getSchema();
			$orders=array();
			foreach($directions as $attribute=>$descending)
			{
				$definition=$this->resolveAttribute($attribute);
				if(is_array($definition))
				{
					if(isset($definition['asc'], $definition['desc']))
						$orders[]=$descending ? $definition['desc'] : $definition['asc'];
					else
						throw new CException(Yii::t('yii','Virtual attribute {name} must specify "asc" and "desc" options.',array('{name}'=>$attribute)));
				}
				else if($definition!==false)
				{
					$attribute=$definition;
					if(isset($schema))
					{
						if(($pos=strpos($attribute,'.'))!==false)
							$attribute=$schema->quoteTableName(substr($attribute,0,$pos)).'.'.$schema->quoteColumnName(substr($attribute,$pos+1));
						else
							$attribute=$schema->quoteColumnName($attribute);
					}
					$orders[]=$descending?$attribute.' DESC':$attribute;
				}
			}
			return implode(', ',$orders);
		}
	}

	/**
	 * Generates a hyperlink that can be clicked to cause sorting.
	 * @param string the attribute name. This must be the actual attribute name, not alias.
	 * If it is an attribute of a related AR object, the name should be prefixed with
	 * the relation name (e.g. 'author.name', where 'author' is the relation name).
	 * @param string the link label. If null, the label will be determined according
	 * to the attribute (see {@link resolveLabel}).
	 * @param array additional HTML attributes for the hyperlink tag
	 * @return string the generated hyperlink
	 */
	public function link($attribute,$label=null,$htmlOptions=array())
	{
		if($label===null)
			$label=$this->resolveLabel($attribute);
		if($this->resolveAttribute($attribute)===false)
			return $label;
		$directions=$this->getDirections();
		if(isset($directions[$attribute]))
		{
			$class=$directions[$attribute] ? 'desc' : 'asc';
			if(isset($htmlOptions['class']))
				$htmlOptions['class'].=' '.$class;
			else
				$htmlOptions['class']=$class;
			$descending=!$directions[$attribute];
			unset($directions[$attribute]);
		}
		else
			$descending=false;
		if($this->multiSort)
			$directions=array_merge(array($attribute=>$descending),$directions);
		else
			$directions=array($attribute=>$descending);

		$url=$this->createUrl(Yii::app()->getController(),$directions);

		return $this->createLink($attribute,$label,$url,$htmlOptions);
	}

	/**
	 * Resolves the attribute label for the specified attribute.
	 * This will invoke {@link CActiveRecord::getAttributeLabel} to determine what label to use.
	 * If the attribute refers to a virtual attribute declared in {@link attributes},
	 * then the label given in the {@link attributes} will be returned instead.
	 * @param string the attribute name.
	 * @return string the attribute label
	 */
	public function resolveLabel($attribute)
	{
		$definition=$this->resolveAttribute($attribute);
		if(is_array($definition))
		{
			if(isset($definition['label']))
				return $definition['label'];
		}
		else if(is_string($definition))
			$attribute=$definition;
		if($this->modelClass!==null)
			return CActiveRecord::model($this->modelClass)->getAttributeLabel($attribute);
		else
			return $attribute;
	}

	/**
	 * Returns the currently requested sort information.
	 * @return array sort directions indexed by attribute names.
	 * The sort direction is true if the corresponding attribute should be
	 * sorted in descending order.
	 */
	public function getDirections()
	{
		if($this->_directions===null)
		{
			$this->_directions=array();
			if(isset($_GET[$this->sortVar]))
			{
				$attributes=explode($this->separators[0],$_GET[$this->sortVar]);
				foreach($attributes as $attribute)
				{
					if(($pos=strpos($attribute,$this->separators[1]))!==false)
					{
						$descending=substr($attribute,$pos+1)===$this->descTag;
						$attribute=substr($attribute,0,$pos);
					}
					else
						$descending=false;

					if(($this->resolveAttribute($attribute))!==false)
					{
						$this->_directions[$attribute]=$descending;
						if(!$this->multiSort)
							return $this->_directions;
					}
				}
			}
		}
		return $this->_directions;
	}

	/**
	 * Returns the sort direction of the specified attribute in the current request.
	 * @param string the attribute name
	 * @return mixed the sort direction of the attribut. True if the attribute should be sorted in descending order,
	 * false if in ascending order, and null if the attribute doesn't need to be sorted.
	 */
	public function getDirection($attribute)
	{
		$this->getDirections();
		return isset($this->_directions[$attribute]) ? $this->_directions[$attribute] : null;
	}

	/**
	 * Creates a URL that can lead to generating sorted data.
	 * @param CController the controller that will be used to create the URL.
	 * @param array the sort directions indexed by attribute names.
	 * The sort direction is true if the corresponding attribute should be
	 * sorted in descending order.
	 * @return string the URL for sorting
	 */
	public function createUrl($controller,$directions)
	{
		$sorts=array();
		foreach($directions as $attribute=>$descending)
			$sorts[]=$descending ? $attribute.$this->separators[1].$this->descTag : $attribute;
		$params=$this->params===null ? $_GET : $this->params;
		$params[$this->sortVar]=implode($this->separators[0],$sorts);
		return $controller->createUrl($this->route,$params);
	}

	/**
	 * Returns the real definition of an attribute given its name.
	 * The resolution is based on {@link attributes} and {@link CActiveRecord::attributeNames}.
	 * When {@link attributes} is an empty array, if the name refers to an attribute of {@link modelClass},
	 * then the name is returned back.
	 * When {@link attributes} is not empty, if the name refers to an attribute declared in {@link attributes},
	 * then the corresponding virtual attribute definition is returned.
	 * In all other cases, false is returned, meaning the name does not refer to a valid attribute.
	 * @param string the attribute name that the user requests to sort on
	 * @return mixed the attribute name or the virtual attribute definition. False if the attribute cannot be sorted.
	 */
	public function resolveAttribute($attribute)
	{
		if($this->attributes!==array())
			$attributes=$this->attributes;
		else if($this->modelClass!==null)
			$attributes=CActiveRecord::model($this->modelClass)->attributeNames();
		else
			return false;
		foreach($attributes as $name=>$definition)
		{
			if(is_string($name))
			{
				if($name===$attribute)
					return $definition;
			}
			else if($definition===$attribute)
				return $attribute;
		}
		return false;
	}

	/**
	 * Creates a hyperlink based on the given label and URL.
	 * You may override this method to customize the link generation.
	 * @param string the name of the attribute that this link is for
	 * @param string the label of the hyperlink
	 * @param string the URL
	 * @param array additional HTML options
	 * @return string the generated hyperlink
	 */
	protected function createLink($attribute,$label,$url,$htmlOptions)
	{
		return CHtml::link($label,$url,$htmlOptions);
	}
}