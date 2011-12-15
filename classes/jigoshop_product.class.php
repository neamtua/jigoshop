<?php
/**
 * Product Class
 *
 * DISCLAIMER
 *
 * Do not edit or add directly to this file if you wish to upgrade Jigoshop to newer
 * versions in the future. If you wish to customise Jigoshop core for your needs,
 * please use our GitHub repository to publish essential changes for consideration.
 *
 * @package    Jigoshop
 * @category   Catalog
 * @author     Jigowatt
 * @copyright  Copyright (c) 2011 Jigowatt Ltd.
 * @license    http://jigoshop.com/license/commercial-edition
 */
class jigoshop_product {
	
	// LEGACY
	private static $attribute_taxonomies = NULL;
	public $children;
	//

	public $id; // : jigoshop_template_functions.php on line 99
	public $exists; // : jigoshop_cart.class.php on line 66
	public $product_type; // : jigoshop_template_functions.php on line 271
	public $sku; // : jigoshop_template_functions.php on line 246

	public $data; // jigoshop_tax.class.php on line 186
	public $post; // for get_title()

	private $regular_price;
	private $sale_price;
	private $sale_price_dates_from	;
	private $sale_price_dates_to;

	private $weight;

	private $tax_status		= 'taxable';
	private $tax_class;

	public $visibility			= 'visible'; // : admin/jigoshop-admin-post-types.php on line 168
	private $featured			= false;

	private $manage_stock		= false;
	private $stock_status		= 'instock';
	private $backorders;
	public $stock; // : admin/jigoshop-admin-post-types.php on line 180

	private	$attributes			= array();

	/**
	 * Loads all product data from custom fields
	 *
	 * @param   int		ID of the product to load
	 * @return	jigoshop_product
	 */
	public function __construct( $ID ) {

		// Grab the product ID & get the product meta data
		// TODO: Change to uppercase for consistency sake
		$this->id = (int) $ID;
		$meta = get_post_custom( $this->id );

		// Check if the product has meta data attached
		// If not then it might not be a product
		$this->exists = (bool) $meta;

		// Get the product type
		// TODO: for some reason this is invalid on first run?
		$terms = wp_get_object_terms( $this->id, 'product_type', array('fields' => 'names') );
		if( ! is_wp_error($terms) )
			$this->product_type = sanitize_title($terms[0]); // should throw error if something has gone wrong

		// Define data
		$this->regular_price				= isset($meta['regular_price'][0]) ? $meta['regular_price'][0] : null;
		$this->sale_price				= isset($meta['sale_price'][0]) 	? $meta['sale_price'][0] : null;
		$this->sale_price_dates_from		= isset($meta['sale_price_dates_from'][0]) ? $meta['sale_price_dates_from'][0] : null;
		$this->sale_price_dates_to		= isset($meta['sale_price_dates_to'][0]) ? $meta['sale_price_dates_to'][0] : null;

		$this->weight					= isset($meta['weight'][0]) ? $meta['weight'][0] : null;

		$this->tax_status				= isset($meta['tax_status'][0]) ? $meta['tax_status'][0] : null;
		$this->tax_class					= isset($meta['tax_class'][0]) ? $meta['tax_class'][0] : null;

		$this->visibility				= isset($meta['visibility'][0]) ? $meta['visibility'][0] : null;
		$this->featured					= isset($meta['featured'][0]) ? $meta['featured'][0] : null;

		$this->manage_stock				= isset($meta['manage_stock'][0]) ? $meta['manage_stock'][0] : null;
		$this->stock_status				= isset($meta['stock_status'][0]) ? $meta['stock_status'][0] : null;
		$this->backorders				= isset($meta['backorders'][0]) ? $meta['backorders'][0] : null;
		$this->stock					= isset($meta['stock'][0]) ? $meta['stock'][0] : null;

		// Get the attributes
		$this->attributes = maybe_unserialize( $meta['product_attributes'][0] );

		// OLD
		$this->get_children();

		return $this;
	}

	/**
	 * Get the main product image or parents image
	 *
	 * @return		html
	 **/
	public function get_image( $size = 'shop_thumbnail' ) {

		// Get the image size
		$size = jigoshop_get_image_size( $size );

		// If product has an image
		if( has_post_thumbnail( $this->id ) )
    		return get_the_post_thumbnail( $this->id, $size );

    	// If product has a parent and that has an image display that
    	if( ($parent_ID = wp_get_post_parent_id( $this->id )) && has_post_thumbnail( $parent_ID ) )
    		return get_the_post_thumbnail( $this->id, $size );
    	
    	// Otherwise just return a placeholder
		return '<img src="'.jigoshop::plugin_url().'/assets/images/placeholder.png" alt="Placeholder" width="'.$image_size[0].'px" height="'.$image_size[1].'px" />';
	}
	
	/**
	 * Get SKU (Stock-keeping unit) - product uniqe ID
	 * 
	 * @return mixed
	 */
	public function get_sku() {
		return $this->sku;
	}
	
	/**
	 * Returns the product's children
	 * 
	 * @return array stdClass objects array
	 */
	function get_children() {

		//load the children if not yet loaded
		if (!is_array($this->children)) {

			$this->children = array();

			if ($this->is_type('variable')) {
				$child_post_type = 'product_variation';
			} else {
				$child_post_type = 'product';
			}

			$children_products = &get_children('post_parent=' . $this->id . '&post_type=' . $child_post_type . '&orderby=menu_order&order=ASC');

			if (is_array($children_products)) {
				
				//@fixme we just retrieved all the data about product children from DB, and we construct jigoshop_product* objects passing only ID to the constructor. In the constructor each product data will be retrieved *again* in the *seperate* queries. Performance fix needed (probably passing whole $child istead of $child->ID will do).
				foreach ($children_products as $child) {
					if ($this->is_type('variable')) {
						$child->product = &new jigoshop_product_variation($child->ID);
					} else {
						$child->product = &new jigoshop_product($child->ID);
					}
				}

				$this->children = (array) $children_products;
			}
		}
		
		return $this->children;
	}

	/**
	 * Reduce stock level of the product
	 * Acts as an alias for modify_stock()
	 *
	 * @param   int		Amount to reduce by
	 * @return	int
	 */
	public function reduce_stock( $by = -1 ) {
		return $this->modify_stock( -$by );
	}

	/**
	 * Increase stock level of the product
	 * Acts as an alias for modify_stock()
	 *
	 * @param   int		Amount to increase by
	 * @return	int
	 */
	public function increase_stock( $by = 1 ) {
		return $this->modify_stock( $by );
	}

	/**
	 * Modifies the stock levels
	 *
	 * @param   int		Amount to modify
	 * @return	int
	 */
	public function modify_stock( $by ) {

		// Only do this if we're updating
		if ( ! $this->managing_stock() )
			return false;
		
		// +- = minus
		$this->stock = $this->stock + $by;
		
		// Update & return the new value
		update_post_meta( $this->id, 'stock', $this->stock );
		return $this->stock;
	}

	/**
	 * Checks the product type
	 *
	 * @param   string		Type to check against
	 * @return	bool
	 */
	public function is_type( $type ) {

		if ( is_array($type) && in_array($this->product_type, $type) )
			return true;
		
		if ($this->product_type == $type)
			return true;
		
		return false;
	}

	/**
	 * Returns whether or not the product has any child product
	 *
	 * @return	bool
	 */
	public function has_child () {
		return (bool) $this->children;
	}

	/**
	 * Checks to see if a product exists
	 *
	 * @return	bool
	 */
	public function exists() {
		return (bool) $this->exists;
	}

	/**
	 * Returns whether or not the product is taxable
	 *
	 * @return	bool
	 */
	public function is_taxable() {
		return ( $this->tax_status == 'taxable' );
	}

	/**
	 * Returns whether or not the product shipping is taxable
	 *
	 * @return	bool
	 */
	public function is_shipping_taxable() {
		return ( $this->is_taxable() || $this->tax_status == 'shipping' );
	}

	/**
	 * Get the product's post data
	 * @deprecated Should be using WP native the_title() right? -Rob
	 * @note: Only used for get_title()
	 *
	 * @return	object
	 */
	public function get_post_data() {
		if (empty($this->post)) {
			$this->post = get_post( $this->id );
		}

		return $this->post;
	}

	/**
	 * Get the product's post data
	 * @deprecated Should be using WP native the_title() right? -Rob
	 * @note: Only used for get_title()
	 *
	 * @return	string
	 */
	public function get_title() {
		$this->get_post_data();
		return apply_filters('jigoshop_product_title', get_the_title($this->post->ID), $this);
	}

	/** 
	 * Get the add to url
	 * @todo look at this function closer
	 *
	 * @return	mixed
	 */
	public function add_to_cart_url() {

		if ($this->is_type('variable')) {
			$url = add_query_arg('add-to-cart', 'variation');
			$url = add_query_arg('product', $this->id, $url);
		}
		else if ( $this->has_child() ) {
			$url = add_query_arg('add-to-cart', 'group');
			$url = add_query_arg('product', $this->id, $url);
		}
		else {
			$url = add_query_arg('add-to-cart', $this->id);
		}

		$url = jigoshop::nonce_url( 'add_to_cart', $url );
		return $url;
	}

	/**
	 * Check if we are managing stock
	 *
	 * @return	bool
	 */
	public function managing_stock() {

		// If we're not managing stock at all
		if (get_option('jigoshop_manage_stock') != 'yes')
			return false;

		return (bool) $this->manage_stock;
	}

	/**
	 * Returns whether or not the product is in stock 
	 * 
	 * @return	bool
	 */
	public function is_in_stock() {

		// If we arent managing stock then it should always be in stock
		if( ! $this->managing_stock() && $this->stock_status == 'instock' )
			return true;

		// Check if we allow backorders
		if( $this->managing_stock() && $this->backorders_allowed() )
			return true;

		// Check if we have stock
		if( $this->managing_stock() && $this->stock )
			return true;
		
		return false;


			/** TODO: Add Support for variations
			// If we have variations
			if( $this->has_child() ) {

				// Loop through children and in this case look for a product with stock
				foreach($this->children as $child) {
					if( ! $child->product->stock <= 0 )
						return true;
				}

				// By default for variations return out of stock
				return false;

			} else {

				if ( $this->data['stock_status'] != 'instock' )
					return false;
				else if( $this->stock <= 0 )
					return false;

			} **/

	}

	/**
	 * Returns whether or not the product can be backordered 
	 * 
	 * @return	bool
	 */
	public function backorders_allowed() {

		if ( $this->backorders == 'yes' || $this->backorders_require_notification() )
			return true;

		return false;
	}

	/**
	 * Returns whether or not the product needs to notify the customer on backorder
	 *
	 * @TODO: Consider a shorter method name?
	 * 
	 * @return	bool
	 */
	public function backorders_require_notification() {

		return ($this->backorders == 'notify');
	}

	/**
	 * Returns whether or not the product has enough stock for the order
	 *
	 * @TODO: Consider a shorter method name?
	 * 
	 * @return	bool
	 */
	public function has_enough_stock( $quantity ) {

		return ($this->backorders_allowed() || $this->stock >= $quantity);
	}
	
	/**
	 * Returns number of items available for sale.
	 * 
	 * @return int
	 */
	public function get_stock_quantity() {
		return (int) $this->stock;
	}
	
	/**
	 * Returns a string representing the availability of the product 
	 * 
	 * @return	string
	 */
	public function get_availability() {

		// Start as in stock
		$notice = array(
			'availability'	=> __( 'In Stock', 'jigoshop' ),
			'class'			=> null,
		);

		// If stock is being managed & has stock
		if ( $this->managing_stock() && $this->stock ) {
			$notice['availability'] .= " &ndash; {$this->stock} ".__(' available', 'jigoshop' );

			// If customers require backorder notification
			if ( $this->backorders_allowed() && $this->backorders_require_notification() ) {
				$notice['availability'] = $notice['availability'] .' ('.__('backorders allowed','jigoshop').')';
			}
		}
		else if ( $this->backorders_allowed() && $this->backorders_require_notification() ) {
				$notice['availability']	= __( 'Available on Backorder', 'jigoshop' );
		}

		// Declare out of stock if we don't have any stock
		if ( ! $this->is_in_stock() ) {
			$notice['availability']	= __( 'Out of Stock', 'jigoshop' );
			$notice['class']		= 'out-of-stock';
		}

		return $notice;
	}

	/**
	 * Returns whether or not the product is featured
	 * 
	 * @return	bool
	 */
	public function is_featured() {
		return (bool) $this->featured;
	}

	/**
	 * Checks if the product is visibile
	 *
	 * @return		bool
	 */
	public function is_visible( ) {

		// Disabled due to incorrect stock handling -Rob
		//if( (bool) $this->stock )
		//	return false;

		switch($this->visibility) {
			case 'hidden':
				return false; 
			break;
			case 'search':
				return is_search();
			break;
			case 'catalog':
				return ! is_search(); // don't display in search results
			break;
			default:
				return true; // By default always display a product
		}
	}
	
	/**
	 * Returns whether or not the product is on sale.
	 * If one of the child products is on sale, product is considered to be on sale.
	 *
	 * TODO: Check children for sale items
	 *
	 * @return bool
	 */
	public function is_on_sale() {
		
		$time = current_time('timestamp');

		// Check if the sale is still in range (if we have a range)
		if ( $this->sale_price_dates_from 	<= $time && 
			 $this->sale_price_dates_to 		>= $time &&
			 $this->sale_price)
			return true;

		// Otherwise if we have a sale price
		if ( ! $this->sale_price_dates_to && $this->sale_price )
			return true;

		// Just incase return false
		return false;
	}

	/**
	 * Returns the product's weight
	 * @deprecated not required since we can just call $this->weight if the var is public
	 *
	 * @return	mixed	weight
	 */
	public function get_weight() {
		return $this->weight;
	}

	/** Returns the price (excluding tax) */
	function get_price_excluding_tax() {
		$price = $this->get_price();

		if (get_option('jigoshop_prices_include_tax') == 'yes') {
			$rate = $this->get_tax_base_rate();

			if ($rate && $rate > 0) {
				$_tax = &new jigoshop_tax();
				$tax_amount = $_tax->calc_tax($price, $rate, true);
				$price = $price - $tax_amount;
			}
		}

		return $price;
	}

	/**
	 * Returns the base tax rate
	 *
	 * @return	???
	 */
	public function get_tax_base_rate() {

		if ($this->is_taxable() && get_option('jigoshop_calc_taxes') == 'yes') {
			$_tax = new jigoshop_tax();
			return $_tax->get_shop_base_rate($this->data['tax_class']);
		}

		return false;
	}

	/**
	 * Returns the percentage saved on sale products
	 * @note was called get_percentage()
	 *
	 * @return	string
	 */
	public function get_percentage_sale() {

		if ( $this->is_on_sale() ) {
			// 100% - sale price percentage over regular price
			$percentage = 100 - ( ($this->sale_price / $this->regular_price) * 100);

			// Round & return
			return round($percentage).'%';
		}

	}

	/**
	 * Returns the products current price
	 *
	 * @return	int
	 */
	public function get_price() {
		return ($this->is_on_sale()) ? $this->sale_price : $this->regular_price;
	}

	/**
	 * Adjust the products price during runtime
	 *
	 * @param	mixed
	 * @return	void
	 */
	public function adjust_price( $new_price ) {
			$this->sale_price += $new_price;
			$this->regular_price += $new_price;
	}

	/**
	 * Returns the price in html format
	 *
	 * @todo	Add support for grouped/variable products
	 *
	 * @return	html
	 */
	public function get_price_html()
	{
		$html = null;

		if ( ! $this->regular_price )
			$html = __( 'Price Not Announced', 'jigoshop' );

		if ( $this->get_price() == 0 ) 
			$html = __( 'Free', 'jigoshop' );

		if ( $this->is_on_sale() ) {
			$html = '
				<del>' . jigoshop_price( $this->regular_price ) . '</del>
				<ins>' . jigoshop_price( $this->sale_price ) . '</ins>';
		}
		else {
			$html = jigoshop_price( $this->regular_price );
		}

		return $html;
	}

	/**
	 * Returns the upsell product ids
	 *
	 * @return	mixed
	 */
	public function get_upsells() {
		return $this->up_sells;
	}

	/**
	 * Returns the cross_sells product ids
	 *
	 * @return	mixed
	 */
	public function get_cross_sells() {
		return $this->cross_sells;
	}

	/**
	 * Returns the product categories
	 *
	 * @return	HTML
	 */
	public function get_categories( $sep = ', ', $before = '', $after = '' ) {
		return get_the_term_list($this->id, 'product_cat', $before, $sep, $after);
	}

	/**
	 * Returns the product tags
	 *
	 * @return	HTML
	 */
	public function get_tags( $sep = ', ', $before = '', $after = '' ) {
		return get_the_term_list($this->id, 'product_tag', $before, $sep, $after);
	}

	/**
	 * Gets all products which have a common category or tag
	 * 
	 * TODO: Add stock check?
	 *
	 * @return	array
	 */
	public function get_related( $limit = 5 ) {

		// Get the tags & categories
		$tags = wp_get_post_terms($this->id, 'product_tag', array('fields' => 'ids'));
		$cats = wp_get_post_terms($this->id, 'product_cat', array('fields' => 'ids'));

		// No queries if we don't have any tags/categories
		if( empty( $cats ) || empty( $tags ) )
			return array();
		
		// Only get related posts that are in stock & visible
		$query = array(
			'posts_per_page'	=> $limit,
			'post_type'			=> 'product',
			'fields'				=> 'ids',
			'orderby'			=> 'rand',
			'meta_query'		=> array(
				array(
					'key'		=> 'visibility',
					'value'		=> array( 'catalog', 'visible' ),
					'compare'	=> 'IN',
				),
			),
			'tax_query'			=> array(
				'relation'			=> 'OR',
				array(
					'taxonomy'		=> 'product_cat',
					'field'			=> 'id',
					'terms'			=> $cats
				),
				array(
					'taxonomy'		=> 'product_tag',
					'field'			=> 'id',
					'terms'			=> $tags
				),
			),
		);

		// Run the query
		$q = get_posts( $query );
		wp_reset_postdata();

		return $q;
	}

	/**
	 * Gets a single product attribute
	 *
	 * @return	string|array
	 **/
	public function get_attribute( $key ) {

		// Get the attribute in question & sanitize just incase
		$attr = $this->attributes[sanitize_title($key)];

		// If its a taxonomy return that
		if( $attr['is_taxonomy'] )
			return wp_get_post_terms( $this->id, 'pa_'.sanitize_title($attr['name']) );

		return $attr['value'];
	}

	/**
	 * Gets the attached product attributes
	 *
	 * @return	array
	 **/
	public function get_attributes() {
		return $this->attributes;
	}

	/**
	 * Checks for any visible attributes attached to the product
	 *
	 * @return	boolean
	 **/
	public function has_attributes() {
		if ( (bool) $this->attributes ) {
			foreach( $this->attributes as $attribute ) {
				return (bool) $attribute['visible'];
			}
		}

		return false;
	}

	/**
	 * Lists attributes in a html table
	 *
	 * @return	html
	 **/
	public function list_attributes() {

		// Check that we have some attributes that are visible
		if ( ! $this->has_attributes() )
			return false;

		// Start the html output
		$html = '<table cellspacing="0" class="shop_attributes">';

		foreach( $this->attributes as $attr ) {

			// If attribute is invisible skip
			if ( ! $attr['visible'] )
				continue;

			// Get Title & Value from attribute array
			$name = wptexturize($attr['name']);
			$value = null;

			if ( (bool) $attr['is_taxonomy'] ) {

				// Get the taxonomy terms
				$product_terms = wp_get_post_terms( $this->id, 'pa_'.sanitize_title($attr['name']) );

				// Convert them into a string
				$terms = array();

				foreach( $product_terms as $term ) {
					$terms[] = $term->name;
				}

				$value = implode(', ', $terms);
			}
			else {
				$value = wptexturize($attr['value']);
			}

			// Generat the remaining html
			$html .= "
			<tr>
				<th>$name</th>
				<td>$value</td>
			</tr>";
		}
		
		$html .= '</table>';
		return $html;
	}
	
	/**
	 * Returns an array of available values for attributes used in product variations
	 * 
	 * @todo Note that this is 'variable product' specific, and should be moved to separate class
	 * with all 'variable product' logic form other methods in this class.
	 * 
	 * @return two dimensional array of attributes and their available values
	 */   
	function get_available_attributes_variations() {
		if (!$this->is_type('variable') || !$this->has_child()) {
			return array();
		}
		
		$attributes = $this->get_attributes();
		
		if(!is_array($attributes)) {
			return array();
		}
		
		$available = array();
		$children = $this->get_children();
		
		foreach ($attributes as $attribute) {
			if ($attribute['variation'] !== 'yes') {
				continue;
			}

			$values = array();
			$name = 'tax_'.sanitize_title($attribute['name']);

			foreach ($children as $child) {
				/* @var $variation jigoshop_product_variation */
				$variation = $child->product;

				//check attributes of all variations that are visible (enabled)
				if ($variation instanceof jigoshop_product_variation && $variation->is_visible()) {
					$options = $variation->get_variation_attributes();
					
					if (is_array($options)) {
						foreach ($options as $aname => $avalue) {
							if ($aname == $name) {
								$values[] = $avalue;
							}
						}
					}
				}
			}
			
			sort( $values );
			
			//empty value indicates that all options for given attribute are available
			if ( in_array(  '', $values)) {
				$options = $attribute['value'];
				if (!is_array($options)) {
					$options = explode(',', $options);
				}
				
				$values = $options;
			}
			  
			//make sure values are unique
			$values = array_unique($values);

			$available[$attribute['name']] = $values;
		}
		
		return $available;
	}

	/**
	 * Get attribute taxonomies. Taxonomies are lazy loaded.
	 * 
	 * @return array of stdClass objects representing attributes
	 */
	public static function getAttributeTaxonomies() {
		global $wpdb;
				
		if(self::$attribute_taxonomies === NULL) {
			self::$attribute_taxonomies = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."jigoshop_attribute_taxonomies;"); 
		}
		
		return self::$attribute_taxonomies;
	}
	
}
