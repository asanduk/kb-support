<?php
/**
 * KB Support REST API
 *
 * @package     KBS
 * @subpackage  Classes/Companies REST API
 * @copyright   Copyright (c) 2020, Mike Howard
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.5
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * KBS_Companies_API Class
 *
 * @since	1.5
 */
class KBS_Companies_API extends KBS_API {

	/**
	 * Company ID
	 *
	 * @since	1.5
	 * @var		int
	 */
	protected $company_id = 0;

	/**
	 * Companies
	 *
	 * @since	1.5
	 * @var		array
	 */
	protected $companies = array();

	/**
	 * Get things going
	 *
	 * @since	1.5
	 */
	public function __construct( $post_type )	{
		$this->post_type = $post_type;
		$obj             = get_post_type_object( $post_type );
		$this->rest_base = ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name;
	} // __construct

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * @since	4.5
	 * @see		register_rest_route()
	 */
    public function register_routes()    {
        register_rest_route(
			$this->namespace . $this->version,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_companies' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				)
			)
		);

		register_rest_route(
			$this->namespace . $this->version,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				'args'   => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'Unique identifier for the %s.', 'kb-support' )
					)
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_company' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' )
				)
			)
		);
    } // register_routes

	/**
     * Checks if a given request has access to read a company.
     *
     * @since   1.5
     * @param	WP_REST_Request	$request	Full details about the request.
	 * @return	bool|WP_Error	True if the request has read access for the item, WP_Error object otherwise.
     */
    public function get_item_permissions_check( $request ) {
		if ( ! $this->is_authenticated( $request ) )	{
			return new WP_Error(
				'rest_forbidden_context',
				$this->errors( 'no_auth' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $this->validate_user() )	{
			return kbs_can_view_customers( $this->user_id );
		}

		return false;
    } // get_item_permissions_check

	/**
	 * Retrieves a single company.
	 *
	 * @since	1.5
	 * @param	WP_REST_Request	$request	Full details about the request
	 * @return	WP_REST_Response|WP_Error	Response object on success, or WP_Error object on failure.
	 */
	public function get_company( $request ) {
		$company = new KBS_Company( absint( $request['id'] ) );

		if ( empty( $company->ID ) )	{
			return $company;
		}

		if ( ! $this->check_read_permission( $company ) )	{
			return new WP_Error(
				'rest_forbidden_context',
				$this->errors( 'no_permission' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$data     = $this->prepare_company_for_response( $company, $request );
		$response = rest_ensure_response( $data );

		return $response;
	} // get_company

	/**
     * Checks if a given request has access to read multiple companies.
     *
     * @since   1.5
     * @param	WP_REST_Request	$request	Full details about the request.
	 * @return	bool|WP_Error	True if the request has read access for the item, WP_Error object otherwise.
     */
    public function get_items_permissions_check( $request ) {
		return $this->get_item_permissions_check( $request );
    } // get_items_permissions_check

	/**
	 * Retrieves a collection of companies.
	 *
	 * @since	1.5
	 * @param	WP_REST_Request		$request	Full details about the request
	 * @return	WP_REST_Response|WP_Error		Response object on success, or WP_Error object on failure
	 */
	function get_companies( $request )	{
		// Retrieve the list of registered collection query parameters.
		$registered = $this->get_collection_params();
		$args       = array();

		/*
		 * This array defines mappings between public API query parameters whose
		 * values are accepted as-passed, and their internal WP_Query parameter
		 * name equivalents (some are the same). Only values which are also
		 * present in $registered will be set.
		 */
		$parameter_mappings = array(
			'exclude'        => 'post__not_in',
			'include'        => 'post__in',
			'offset'         => 'offset',
			'order'          => 'order',
			'orderby'        => 'orderby',
			'page'           => 'paged'
		);

		/*
		 * For each known parameter which is both registered and present in the request,
		 * set the parameter's value on the query $args.
		 */
		foreach ( $parameter_mappings as $api_param => $wp_param ) {
			if ( isset( $registered[ $api_param ], $request[ $api_param ] ) ) {
				$args[ $wp_param ] = $request[ $api_param ];
			}
		}

		// Ensure our per_page parameter overrides any provided posts_per_page filter.
		if ( isset( $registered['per_page'] ) ) {
			$args['posts_per_page'] = $request['per_page'];
		}

		// Force the post_type argument, since it's not a user input variable.
		$args['post_type'] = $this->post_type;

		/**
		 * Filters the query arguments for a request.
		 *
		 * Enables adding extra arguments or setting defaults for a company collection request.
		 *
		 * @since	1.5
		 * @param	array			$args		Key value array of query var to query value
		 * @param	WP_REST_Request	$request	The request used
		 */
		$args            = apply_filters( "rest_{$this->post_type}_query", $args, $request );
		$query_args      = $this->prepare_items_query( $args, $request );
		$companies_query = new WP_Query();
		$query_result    = $companies_query->query( $query_args );
		$companies       = array();

		foreach ( $query_result as $_company ) {
			if ( ! $this->check_read_permission( $_company ) ) {
				continue;
			}

			$company     = new KBS_Company( $_company->ID );
			$data        = $this->prepare_company_for_response( $company, $request );
			$companies[] = $this->prepare_response_for_collection( $data );
		}

		$page        = (int) $query_args['paged'];
		$total_posts = $companies_query->found_posts;

		if ( $total_posts < 1 ) {
			// Out-of-bounds, run the query again without LIMIT for total count.
			unset( $query_args['paged'] );

			$count_query = new WP_Query();
			$count_query->query( $query_args );
			$total_posts = $count_query->found_posts;
		}

		$max_pages = ceil( $total_posts / (int) $companies_query->query_vars['posts_per_page'] );

		if ( $page > $max_pages && $total_posts > 0 ) {
			return new WP_Error(
				'rest_post_invalid_page_number',
				__( 'The page number requested is larger than the number of pages available.', 'kb-support' ),
				array( 'status' => 400 )
			);
		}

		$response = rest_ensure_response( $companies );

		$response->header( 'X-WP-Total', (int) $total_posts );
		$response->header( 'X-WP-TotalPages', (int) $max_pages );

		$request_params = $request->get_query_params();
		$base           = add_query_arg( urlencode_deep( $request_params ), rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) );

		if ( $page > 1 ) {
			$prev_page = $page - 1;

			if ( $prev_page > $max_pages ) {
				$prev_page = $max_pages;
			}

			$prev_link = add_query_arg( 'page', $prev_page, $base );
			$response->link_header( 'prev', $prev_link );
		}
		if ( $max_pages > $page ) {
			$next_page = $page + 1;
			$next_link = add_query_arg( 'page', $next_page, $base );

			$response->link_header( 'next', $next_link );
		}

		return $response;
	} // get_companies

	/**
	 * Prepares a single company output for response.
	 *
	 * @since	1.5
	 * @param	KBS_Company			$company	KBS_Company object
	 * @param	WP_REST_Request		$request	Request object
	 * @return	WP_REST_Response	Response object
	 */
	public function prepare_company_for_response( $company, $request )	{
		$data     = array();

		$data['id'] = $company->ID;

		if ( ! empty( $company->name ) )	{
			$data['name'] = $company->name;
		}

		if ( ! empty( $company->customer ) )	{
			$data['customer'] = $company->customer;
		}

		if ( ! empty( $company->contact ) )	{
			$data['contact'] = $company->contact;
		}

		if ( ! empty( $company->email ) )	{
			$data['email'] = $company->email;
		}

		if ( ! empty( $company->phone ) )	{
			$data['phone'] = $company->phone;
		}

		if ( ! empty( $company->website ) )	{
			$data['website'] = $company->website;
		}

		if ( ! empty( $company->logo ) )	{
			$data['logo'] = $company->logo;
		}

		if ( ! empty( $company->date_created ) )	{
			$data['date_created'] = $company->date_created;
		}

		if ( ! empty( $company->date_modified ) )	{
			$data['date_modified'] = $company->date_modified;
		}

		// Wrap the data in a response object.
		$response = rest_ensure_response( $data );
		$links    = $this->prepare_links( $company );

		$response->add_links( $links );

		/**
		 * Filters the company data for a response.
		 *
		 * @since	1.5
		 *
		 * @param WP_REST_Response	$response	The response object
		 * @param KBS_Company		$company	Company object
		 * @param WP_REST_Request	$request	Request object
		 */
		return apply_filters( "rest_prepare_{$this->post_type}", $response, $company, $request );
	} // prepare_company_for_response

	/**
	 * Retrieves the query params for the posts collection.
	 *
	 * @since	1.5
	 * @return	array	Collection parameters
	 */
	public function get_collection_params() {
		$query_params = parent::get_collection_params();

		$query_params['context']['default'] = 'view';

		$query_params['number'] = array(
			'description'       => __( 'Maximum number of companies to be returned in result set.', 'kb-support' ),
			'type'              => 'integer',
			'default'           => 20,
			'minimum'           => 1,
			'maximum'           => 100,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg'
		);

		$query_params['exclude'] = array(
			'description' => __( 'Ensure result set excludes specific IDs.' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'integer'
			),
			'default'     => array()
		);

		$query_params['include'] = array(
			'description' => __( 'Limit result set to specific IDs.' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'integer'
			),
			'default'     => array()
		);

		$query_params['offset'] = array(
			'description' => __( 'Offset the result set by a specific number of items.' ),
			'type'        => 'integer'
		);

		$query_params['order'] = array(
			'description' => __( 'Order sort attribute ascending or descending.' ),
			'type'        => 'string',
			'default'     => 'desc',
			'enum'        => array( 'asc', 'desc' )
		);

		$query_params['orderby'] = array(
			'description' => __( 'Sort collection by object attribute.' ),
			'type'        => 'string',
			'default'     => 'title',
			'enum'        => array(
				'customer',
				'id',
				'include',
				'title'
			),
		);

		$post_type = get_post_type_object( $this->post_type );

		/**
		 * Filter collection parameters for the companies controller.
		 *
		 * The dynamic part of the filter `$this->post_type` refers to the post
		 * type slug for the controller.
		 *
		 * This filter registers the collection parameter, but does not map the
		 * collection parameter to an internal WP_Query parameter. Use the
		 * `rest_{$this->post_type}_query` filter to set WP_Query parameters.
		 *
		 * @since	1.5
		 *
		 * @param	array			$query_params	JSON Schema-formatted collection parameters.
		 * @param	WP_Post_Type	$post_type		Post type object.
		 */
		return apply_filters( "rest_{$this->post_type}_collection_params", $query_params, $post_type );
	} // get_collection_params

	/**
	 * Checks if a company can be read.
	 *
	 * @since	1.5
	 * @param	object	KBS_Company object
	 * @return	bool	Whether the company can be read.
	 */
	public function check_read_permission( $company )	{
		return kbs_can_view_customers( $this->user_id );
	} // check_read_permission

	/**
	 * Prepares links for the request.
	 *
	 * @since	1.5
	 * @param	KBS_Company	$company		KBS Company object
	 * @return	array		Links for the given company
	 */
	protected function prepare_links( $company ) {
		$base = sprintf( '%s/%s', $this->namespace . $this->version, $this->rest_base );

		// Entity meta.
		$links = array(
			'self'       => array(
				'href' => rest_url( trailingslashit( $base ) . $company->ID ),
			),
			'collection' => array(
				'href' => rest_url( $base ),
			)
		);

		// If we have a featured media, add that.
		$featured_media = get_post_thumbnail_id( $company->ID );
		if ( $featured_media ) {
			$image_url = rest_url( 'wp/v2/media/' . $featured_media );

			$links['https://api.w.org/featuredmedia'] = array(
				'href'       => $image_url,
				'embeddable' => true,
			);
		}

		if ( ! empty( $company->customer ) )	{
			$links['customer'] = array(
				'href'       => rest_url( 'kbs/v1/customers/' . $company->customer ),
				'embeddable' => true,
			);
		}

		return $links;
	} // prepare_links

	/**
	 * Determines the allowed query_vars for a get_items() response and prepares
	 * them for WP_Query.
	 *
	 * @since	1.5
	 * @param	array			$prepared_args	Optional. Prepared WP_Query arguments. Default empty array
	 * @param	WP_REST_Request	$request		Optional. Full details about the request
	 * @return	array			Items query arguments.
	 */
	protected function prepare_items_query( $prepared_args = array(), $request = null ) {
		$query_args = array();

		foreach ( $prepared_args as $key => $value ) {
			/**
			 * Filters the query_vars used in get_items() for the constructed query.
			 *
			 * The dynamic portion of the hook name, `$key`, refers to the query_var key.
			 *
			 * @since	1.5
			 *
			 * @param	string	$value	The query_var value.
			 */
			$query_args[ $key ] = apply_filters( "rest_query_var-{$key}", $value ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}

		// Map to proper WP_Query orderby param.
		if ( isset( $query_args['orderby'] ) && isset( $request['orderby'] ) ) {
			$orderby_mappings = array(
				'id'            => 'ID',
				'include'       => 'post__in',
				'slug'          => 'post_name',
				'include_slugs' => 'post_name__in'
			);

			if ( isset( $orderby_mappings[ $request['orderby'] ] ) ) {
				$query_args['orderby'] = $orderby_mappings[ $request['orderby'] ];
			}
		}

		return $query_args;
	} // prepare_items_query

} // KBS_Companies_API
