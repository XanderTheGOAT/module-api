<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Name:		Shop API
 *
 * Description:	This controller handles Shop API methods
 *
 **/

require_once '_api.php';

/**
 * OVERLOADING NAILS' API MODULES
 *
 * Note the name of this class; done like this to allow apps to extend this class.
 * Read full explanation at the bottom of this file.
 *
 **/

class NAILS_Shop extends NAILS_API_Controller
{
	protected $_authorised;
	protected $_error;


	// --------------------------------------------------------------------------


	/**
	 * Constructor
	 *
	 * @access	public
	 * @return	void
	 *
	 **/
	public function __construct()
	{
		parent::__construct();

		// --------------------------------------------------------------------------

		//	Check this module is enabled in settings
		if ( ! module_is_enabled( 'shop' ) ) :

			//	Cancel execution, module isn't enabled
			$this->_method_not_found( $this->uri->segment( 2 ) );

		endif;
	}


	// --------------------------------------------------------------------------


	public function basket()
	{
		$_method = $this->uri->segment( 4 );

		if ( method_exists( $this, '_basket_' . $_method ) ) :

			$this->{'_basket_' . $_method}();

		else :

			$this->_method_not_found( 'basket/' . $_method );

		endif;
	}


	// --------------------------------------------------------------------------


	protected function _basket_add()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		if ( ! $this->shop_basket_model->add( $this->uri->rsegment( 4 ), $this->uri->rsegment( 5 ) ) ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_remove()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		if ( ! $this->shop_basket_model->remove( $this->uri->rsegment( 4 ), $this->uri->rsegment( 5 ) ) ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_increment()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		if ( ! $this->shop_basket_model->increment( $this->uri->rsegment( 4 ) ) ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_decrement()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		if ( ! $this->shop_basket_model->decrement( $this->uri->rsegment( 4 ) ) ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_add_voucher()
	{
		$_out		= array();
		$_voucher	= $this->shop_voucher_model->validate( $this->input->post( 'voucher' ), get_basket() );

		if ( $_voucher ) :

			if ( ! $this->shop_basket_model->add_voucher( $_voucher->code ) ) :

				$_out['status']	= 400;
				$_out['error']	= $this->shop_basket_model->last_error();

			endif;

		else :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_voucher_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_remove_voucher()
	{
		$_out = array();

		// --------------------------------------------------------------------------

		if ( ! $this->shop_basket_model->remove_voucher() ) :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_basket_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_set_shipping_method()
	{
		$_out		= array();
		$_method	= $this->shop_shipping_model->validate( $this->input->post( 'shipping_method' ) );

		if ( $_method ) :

			if ( ! $this->shop_basket_model->add_shipping_method( $_method->id ) ) :

				$_out['status']	= 400;
				$_out['error']	= $this->shop_basket_model->last_error();

			endif;

		else :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_shipping_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}


	// --------------------------------------------------------------------------


	protected function _basket_set_currency()
	{
		$_out		= array();
		$_currency	= $this->shop_currency_model->get_by_code( $this->input->post( 'currency' ) );

		if ( $_currency ) :

			$this->session->set_userdata( 'shop_currency', $_currency->code );

			if ( $this->user_model->is_logged_in() ) :

				//	Save to the user object
				$this->user_model->update( active_user( 'id' ), array( 'shop_currency' => $_currency->code ) );

			endif;

		else :

			$_out['status']	= 400;
			$_out['error']	= $this->shop_shipping_model->last_error();

		endif;

		// --------------------------------------------------------------------------

		$this->_out( $_out );
	}
}


// --------------------------------------------------------------------------


/**
 * OVERLOADING NAILS' API MODULES
 *
 * The following block of code makes it simple to extend one of the core API
 * controllers. Some might argue it's a little hacky but it's a simple 'fix'
 * which negates the need to massively extend the CodeIgniter Loader class
 * even further (in all honesty I just can't face understanding the whole
 * Loader class well enough to change it 'properly').
 *
 * Here's how it works:
 *
 * CodeIgniter instantiate a class with the same name as the file, therefore
 * when we try to extend the parent class we get 'cannot redeclare class X' errors
 * and if we call our overloading class something else it will never get instantiated.
 *
 * We solve this by prefixing the main class with NAILS_ and then conditionally
 * declaring this helper class below; the helper gets instantiated et voila.
 *
 * If/when we want to extend the main class we simply define NAILS_ALLOW_EXTENSION_CLASSNAME
 * before including this PHP file and extend as normal (i.e in the same way as below);
 * the helper won't be declared so we can declare our own one, app specific.
 *
 **/

if ( ! defined( 'NAILS_ALLOW_EXTENSION_SHOP' ) ) :

	class Shop extends NAILS_Shop
	{
	}

endif;

/* End of file shop.php */
/* Location: ./modules/api/controllers/shop.php */