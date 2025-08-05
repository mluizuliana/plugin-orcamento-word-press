<?php
/*
Plugin Name: Cotação
Plugin URI: https://seudominio.com
Description: Permite que o cliente solicite orçamento em vez de finalizar pedido no WooCommerce, incluindo observações e arquivo STL.
Version: 1.5
Author: Seu Nome
Author URI: https://seudominio.com
License: GPL2
*/

// Bloqueia acesso direto
if ( !defined( 'ABSPATH' ) ) exit;

// Adiciona o botão no carrinho
add_action( 'woocommerce_proceed_to_checkout', 'cotacao_add_request_quote_button', 20 );
function cotacao_add_request_quote_button() {
    echo '<a href="' . esc_url( add_query_arg( 'solicitar_orcamento', 'true', wc_get_cart_url() ) ) . '" 
             class="button alt">Solicitar Orçamento</a>';
}

// Intercepta o clique e cria um pedido com status "cotacao"
add_action( 'template_redirect', 'cotacao_process_quote_request' );
function cotacao_process_quote_request() {
    if ( isset($_GET['solicitar_orcamento']) && $_GET['solicitar_orcamento'] === 'true' && !WC()->cart->is_empty() ) {
        
        $current_user = wp_get_current_user();
        $order = wc_create_order();

        // Adiciona produtos do carrinho ao pedido
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $item_id = $order->add_product(
                $cart_item['data'],
                $cart_item['quantity'],
                array(
                    'variation' => $cart_item['variation'],
                )
            );

            // Observações do cliente (extraídas do campo JSON "Prad selection raw")
            if ( isset($cart_item['prad_selection_raw']) ) {
                $prad_raw = json_decode(stripslashes($cart_item['prad_selection_raw']), true);
                if (is_array($prad_raw)) {
                    foreach ($prad_raw as $field) {
                        if (isset($field['label']) && $field['label'] === 'Observações sobre o trabalho') {
                            wc_add_order_item_meta($item_id, 'Observações', $field['value']);
                        }
                    }
                }
            }

            // Arquivo STL enviado pelo cliente
            if ( isset($cart_item['dnd-wc-file-upload']) ) {
                $uploaded_files = maybe_unserialize($cart_item['dnd-wc-file-upload']);
                if ( is_array($uploaded_files) ) {
                    foreach ($uploaded_files as $file) {
                        $upload_dir = wp_upload_dir();
                        $file_url = $upload_dir['baseurl'] . '/wc_drag-n-drop_uploads/' . $file;
                        wc_add_order_item_meta(
                            $item_id,
                            'Arquivo STL',
                            '<a href="' . esc_url($file_url) . '" target="_blank">' . esc_html($file) . '</a>'
                        );
                    }
                }
            }
        }

        // Define dados do cliente
        if ( $current_user->ID ) {
            $order->set_customer_id( $current_user->ID );
        }

        // Define status "cotacao"
        $order->update_status( 'cotacao', 'Pedido de orçamento solicitado.' );

        WC()->cart->empty_cart();

        wp_safe_redirect( add_query_arg( 'cotacao_recebida', 'true', wc_get_cart_url() ) );
        exit;
    }
}

// Mensagem de confirmação
add_action( 'woocommerce_before_cart', 'cotacao_thank_you_message' );
function cotacao_thank_you_message() {
    if ( isset($_GET['cotacao_recebida']) && $_GET['cotacao_recebida'] === 'true' ) {
        wc_print_notice( 'Sua solicitação de orçamento foi enviada com sucesso! Em breve entraremos em contato.', 'success' );
    }
}

// Adiciona novo status "cotacao"
add_action( 'init', 'cotacao_register_status' );
function cotacao_register_status() {
    register_post_status( 'wc-cotacao', array(
        'label'                     => _x( 'Cotação', 'Order status', 'woocommerce' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Cotação (%s)', 'Cotações (%s)' )
    ) );
}

// Adiciona o status à lista
add_filter( 'wc_order_statuses', 'cotacao_add_to_order_statuses' );
function cotacao_add_to_order_statuses( $order_statuses ) {
    $new_order_statuses = array();
    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;
        if ( 'wc-pending' === $key ) {
            $new_order_statuses['wc-cotacao'] = _x( 'Cotação', 'Order status', 'woocommerce' );
        }
    }
    return $new_order_statuses;
}

// Exibir Observações e STL no admin e e-mails
add_action( 'woocommerce_order_item_meta_end', 'cotacao_exibir_observacoes_emails', 10, 4 );
function cotacao_exibir_observacoes_emails( $item_id, $item, $order, $plain_text ) {
    $observacoes = wc_get_order_item_meta( $item_id, 'Observações', true );
    $arquivo_stl = wc_get_order_item_meta( $item_id, 'Arquivo STL', true );

    if ( $observacoes ) {
        echo '<p><strong>Observações:</strong> ' . esc_html( $observacoes ) . '</p>';
    }
    if ( $arquivo_stl ) {
        echo '<p><strong>Arquivo STL:</strong> ' . $arquivo_stl . '</p>';
    }
}
