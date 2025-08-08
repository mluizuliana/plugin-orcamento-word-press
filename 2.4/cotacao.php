<?php 
/*
Plugin Name: Cotação com Campos Extras Dental Mash
Plugin URI: https://www.cocorico.live/
Description: Permite que o cliente solicite orçamento em vez de finalizar pedido no WooCommerce, incluindo observações, arquivo STL e campos extras de cadastro (CPF, Nome completo, Telefone, Endereço).
Version: 2.0
Author: Meigan Luiz Uliana
Author URI: https://github.com/mluizuliana
License: GPL2
*/

// Bloqueia acesso direto
if ( !defined( 'ABSPATH' ) ) exit;

/* ==============================
   BOTÃO DE SOLICITAR ORÇAMENTO
   ============================== */
add_action( 'woocommerce_proceed_to_checkout', 'cotacao_add_request_quote_button', 20 );
function cotacao_add_request_quote_button() {
    // Se não estiver logado, força login
    if ( !is_user_logged_in() ) {
        $login_url = wc_get_page_permalink( 'myaccount' );
        echo '<a href="' . esc_url( $login_url ) . '" class="button alt">Solicitar orçamento</a>';
    } else {
        echo '<a href="' . esc_url( add_query_arg( 'solicitar_orcamento', 'true', wc_get_cart_url() ) ) . '" 
                 class="button alt">Solicitar Orçamento</a>';
    }
}

/* ==============================
   PROCESSAMENTO DO PEDIDO
   ============================== */
add_action( 'template_redirect', 'cotacao_process_quote_request' );
function cotacao_process_quote_request() {
    if ( isset($_GET['solicitar_orcamento']) && $_GET['solicitar_orcamento'] === 'true' && !WC()->cart->is_empty() ) {
        
        // Se não estiver logado, manda para login
        if ( !is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

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

            // Observações do cliente
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

            // Arquivo STL
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

        // Define cliente
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

/* ==============================
   MENSAGEM DE SUCESSO
   ============================== */
add_action( 'template_redirect', 'cotacao_success_message' );
function cotacao_success_message() {
    if ( isset($_GET['cotacao_recebida']) && $_GET['cotacao_recebida'] === 'true' ) {
        wc_add_notice( __( 'Sua cotação foi criada com sucesso! Em breve entraremos em contato.', 'woocommerce' ), 'success' );
    }
}

add_action( 'wp_footer', 'cotacao_show_notices' );
function cotacao_show_notices() {
    if ( function_exists('wc_print_notices') ) {
        wc_print_notices();
    }
}

/* ==============================
   STATUS PERSONALIZADO
   ============================== */
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

/* ==============================
   EXIBIR OBSERVAÇÕES E STL
   ============================== */
// Mantemos o hook porque você quer a exibição no admin/front —
// mas **não** durante o envio de e-mails (para evitar duplicação).
add_action( 'woocommerce_order_item_meta_end', 'cotacao_exibir_observacoes_emails', 10, 4 );
function cotacao_exibir_observacoes_emails( $item_id, $item, $order, $plain_text ) {
    // Detecta contexto de e-mail de forma robusta:
    $in_email = false;
    // 1) variável global $email existe durante templates de e-mail
    global $email;
    if ( isset( $email ) && is_object( $email ) && is_a( $email, 'WC_Email' ) ) {
        $in_email = true;
    }
    // 2) ações relacionadas a emails já terem sido disparadas
    if ( did_action( 'woocommerce_email_before_order_table' ) || did_action( 'woocommerce_email_after_order_table' ) || did_action( 'woocommerce_email_order_items' ) ) {
        $in_email = true;
    }

    // Se estamos no contexto de e-mail, não imprimir aqui (evita duplicação)
    if ( $in_email ) {
        return;
    }

    // Caso contrário, exibe normalmente (admin / front)
    $observacoes = wc_get_order_item_meta( $item_id, 'Observações', true );
    $arquivo_stl = wc_get_order_item_meta( $item_id, 'Arquivo STL', true );

    if ( $observacoes ) {
        echo '<p><strong>Observações:</strong> ' . esc_html( $observacoes ) . '</p>';
    }
    if ( $arquivo_stl ) {
        echo '<p><strong>Arquivo STL:</strong> ' . $arquivo_stl . '</p>';
    }
}

/* ==============================
   CAMPOS EXTRAS NO CADASTRO
   ============================== */
add_action( 'woocommerce_register_form', 'custom_extra_register_fields' );
function custom_extra_register_fields() { ?>
    <p class="form-row form-row-wide">
        <label for="reg_billing_first_name">Nome Completo <span class="required">*</span></label>
        <input type="text" class="input-text" name="billing_first_name" id="reg_billing_first_name"
               value="<?php if ( ! empty( $_POST['billing_first_name'] ) ) echo esc_attr( $_POST['billing_first_name'] ); ?>" />
    </p>
    <p class="form-row form-row-wide">
        <label for="reg_billing_cpf">CPF <span class="required">*</span></label>
        <input type="text" class="input-text" name="billing_cpf" id="reg_billing_cpf"
               value="<?php if ( ! empty( $_POST['billing_cpf'] ) ) echo esc_attr( $_POST['billing_cpf'] ); ?>" />
    </p>
    <p class="form-row form-row-wide">
        <label for="reg_billing_phone">Telefone Celular <span class="required">*</span></label>
        <input type="text" class="input-text" name="billing_phone" id="reg_billing_phone"
               value="<?php if ( ! empty( $_POST['billing_phone'] ) ) echo esc_attr( $_POST['billing_phone'] ); ?>" />
    </p>
    <p class="form-row form-row-wide">
        <label for="reg_billing_postcode">CEP <span class="required">*</span></label>
        <input type="text" class="input-text" name="billing_postcode" id="reg_billing_postcode"
               value="<?php if ( ! empty( $_POST['billing_postcode'] ) ) echo esc_attr( $_POST['billing_postcode'] ); ?>" />
    </p>
    <p class="form-row form-row-wide">
        <label for="reg_billing_address_1">Endereço <span class="required">*</span></label>
        <input type="text" class="input-text" name="billing_address_1" id="reg_billing_address_1"
               value="<?php if ( ! empty( $_POST['billing_address_1'] ) ) echo esc_attr( $_POST['billing_address_1'] ); ?>" />
    </p>
    <p class="form-row form-row-wide">
        <label for="reg_billing_city">Cidade <span class="required">*</span></label>
        <input type="text" class="input-text" name="billing_city" id="reg_billing_city"
               value="<?php if ( ! empty( $_POST['billing_city'] ) ) echo esc_attr( $_POST['billing_city'] ); ?>" />
    </p>
    <p class="form-row form-row-wide">
        <label for="reg_billing_state">Estado <span class="required">*</span></label>
        <input type="text" class="input-text" name="billing_state" id="reg_billing_state"
               value="<?php if ( ! empty( $_POST['billing_state'] ) ) echo esc_attr( $_POST['billing_state'] ); ?>" />
    </p>
<?php }

add_action( 'woocommerce_register_post', 'custom_validate_extra_register_fields', 10, 3 );
function custom_validate_extra_register_fields( $username, $email, $validation_errors ) {
    $campos = [
        'billing_first_name' => 'Nome Completo',
        'billing_cpf'        => 'CPF',
        'billing_phone'      => 'Telefone',
        'billing_postcode'   => 'CEP',
        'billing_address_1'  => 'Endereço',
        'billing_city'       => 'Cidade',
        'billing_state'      => 'Estado'
    ];
    foreach ($campos as $campo => $label) {
        if ( empty( $_POST[$campo] ) ) {
            $validation_errors->add( $campo . '_error', "Por favor, informe seu {$label}." );
        }
    }
}

add_action( 'woocommerce_created_customer', 'custom_save_extra_register_fields' );
function custom_save_extra_register_fields( $customer_id ) {
    $campos = [
        'billing_first_name',
        'billing_cpf',
        'billing_phone',
        'billing_postcode',
        'billing_address_1',
        'billing_city',
        'billing_state'
    ];
    foreach ($campos as $campo) {
        if ( isset( $_POST[$campo] ) ) {
            update_user_meta( $customer_id, $campo, sanitize_text_field( $_POST[$campo] ) );
        }
    }
}

// PERMITIR EDIÇÃO DE PEDIDOS COM STATUS "cotacao" NO ADMIN
add_filter('wc_order_is_editable', 'permitir_edicao_pedido_cotacao', 10, 2);
function permitir_edicao_pedido_cotacao($is_editable, $order) {
    if ( $order->get_status() === 'cotacao' ) {
        return true;
    }
    return $is_editable;
}

// Adiciona o campo no admin do pedido
add_action( 'woocommerce_admin_order_data_after_order_details', 'cotacao_adicionar_obs_admin' );
function cotacao_adicionar_obs_admin( $order ) {
    woocommerce_wp_textarea_input( array(
        'id'          => '_cotacao_observacao_admin',
        'label'       => 'Observação do avaliador',
        'placeholder' => 'Ex: Como o item contém estrutura metálica, o custo será R$ 320,00',
        'description' => 'Essa observação será visível para o cliente após a avaliação.',
        'value'       => get_post_meta( $order->get_id(), '_cotacao_observacao_admin', true ),
    ) );
}

// Salva a observação no pedido
add_action( 'woocommerce_process_shop_order_meta', 'cotacao_salvar_obs_admin' );
function cotacao_salvar_obs_admin( $post_id ) {
    if ( isset( $_POST['_cotacao_observacao_admin'] ) ) {
        update_post_meta( $post_id, '_cotacao_observacao_admin', wp_kses_post( $_POST['_cotacao_observacao_admin'] ) );
    }
}

// Adiciona a observação do avaliador aos e-mails
add_action( 'woocommerce_email_after_order_table', 'cotacao_adicionar_obs_email', 10, 4 );
function cotacao_adicionar_obs_email( $order, $sent_to_admin, $plain_text, $email ) {
    $obs = get_post_meta( $order->get_id(), '_cotacao_observacao_admin', true );
    
    if ( $obs ) {
        if ( $plain_text ) {
            // Para e-mails em texto puro
            echo "\n" . 'Observação do avaliador:' . "\n" . $obs . "\n";
        } else {
            // Para e-mails em HTML
            echo '<h3>Observação do avaliador</h3>';
            echo '<p style="border: 1px solid #ccc; padding: 10px; background: #f3f3f3;">' . nl2br( esc_html( $obs ) ) . '</p>';
        }
    }
}
