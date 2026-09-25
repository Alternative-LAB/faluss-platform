<?php
if(parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1'||!defined('WP_CLI')||!WP_CLI){throw new RuntimeException('Disposable local CLI only');}
global $wpdb;
$email='studio-052-'.bin2hex(random_bytes(4)).'@example.test';
$user=wp_insert_user(['user_login'=>'studio_'.bin2hex(random_bytes(6)),'user_email'=>$email,'user_pass'=>wp_generate_password(32),'role'=>'subscriber']);
$id=Faluss_Identity_Registry::activate_for_wp_user($user);$slug='studio-052-'.bin2hex(random_bytes(3));
Faluss_Identity_Public_Profile::reserve_public_slug($id,$slug);$tables=Faluss_Identity_Schema::get_table_names();
$wpdb->update($tables['public_profiles'],['display_name'=>'Camille','publication_status'=>'published'],['faluss_id'=>$id]);
$wpdb->update($tables['profiles'],['onboarding_choice'=>'create_card','onboarding_next_step'=>'complete'],['faluss_id'=>$id]);
$studio=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Studio local','post_name'=>'studio-local-052','post_content'=>'[faluss_link_studio]']);
$shortcode=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Carte locale','post_name'=>'card-local-052','post_content'=>'[faluss_link_card identifier="'.$slug.'"]']);
$widget=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Elementor local','post_name'=>'elementor-local-052']);
$data=[['id'=>'a1b2c3d4','elType'=>'section','settings'=>[],'elements'=>[['id'=>'e5f6a7b8','elType'=>'column','settings'=>['_column_size'=>100],'elements'=>[['id'=>'c9d0e1f2','elType'=>'widget','widgetType'=>'faluss_link_card','settings'=>['identifier'=>$slug,'presentation'=>'compact'],'elements'=>[]]]]]]];
update_post_meta($widget,'_elementor_data',wp_slash(wp_json_encode($data)));update_post_meta($widget,'_elementor_edit_mode','builder');update_post_meta($widget,'_elementor_version',ELEMENTOR_VERSION);
echo json_encode(['email'=>$email,'slug'=>$slug,'studio'=>get_permalink($studio),'shortcode'=>get_permalink($shortcode),'elementor'=>get_permalink($widget)]);
