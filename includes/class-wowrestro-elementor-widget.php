<?php
/** Elementor menu widget. @package WowRestro */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Elementor_Menu_Widget extends \Elementor\Widget_Base {
	public function get_name() {
		return 'wowrestro-menu'; }
	public function get_title() {
		return __( 'WowRestro Menu', 'wowrestro' ); }
	public function get_icon() {
		return 'eicon-products'; }
	public function get_categories() {
		return array( 'woocommerce-elements' ); }
	protected function register_controls() {
		$this->start_controls_section( 'menu', array( 'label' => __( 'Menu', 'wowrestro' ) ) );
		$this->add_control(
			'template',
			array(
				'label'   => __( 'Template', 'wowrestro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array( '' => __( 'Use WowRestro default', 'wowrestro' ) ) + WowRestro_Storefront::templates(),
			)
		);
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Display', 'wowrestro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''     => __( 'Use WowRestro default', 'wowrestro' ),
					'tabs' => __( 'Category tabs', 'wowrestro' ),
					'list' => __( 'List', 'wowrestro' ),
				),
			)
		);
		$this->add_control(
			'location',
			array(
				'label' => __( 'Location slug', 'wowrestro' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);
		$this->end_controls_section();
	}
	protected function render() {
		echo WowRestro_Storefront::render_menu( $this->get_settings_for_display() ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes its complete markup.
}
