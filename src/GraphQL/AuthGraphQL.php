<?php
/**
 * WPGraphQL authentication mutations.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\GraphQL;

use Sagiris\Signalboard\Auth\AuthTokenService;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes register / login / refreshToken as WPGraphQL mutations.
 *
 * These delegate to the same {@see AuthTokenService} the REST routes use, so a
 * token minted over GraphQL is indistinguishable from one minted over REST and
 * is accepted by the same {@see \Sagiris\Signalboard\Auth\TokenAuthenticator}.
 * Registered only on `graphql_register_types`, which fires exclusively when
 * WPGraphQL is active.
 */
final class AuthGraphQL {

	/**
	 * Register the auth mutations.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_graphql_mutation' ) ) {
			return;
		}

		$output_fields = array(
			'accessToken'  => array(
				'type'    => 'String',
				'resolve' => static function ( $payload ) {
					return $payload['accessToken'] ?? null;
				},
			),
			'refreshToken' => array(
				'type'    => 'String',
				'resolve' => static function ( $payload ) {
					return $payload['refreshToken'] ?? null;
				},
			),
			'tokenType'    => array(
				'type'    => 'String',
				'resolve' => static function ( $payload ) {
					return $payload['tokenType'] ?? null;
				},
			),
			'expiresIn'    => array(
				'type'    => 'Int',
				'resolve' => static function ( $payload ) {
					return isset( $payload['expiresIn'] ) ? (int) $payload['expiresIn'] : null;
				},
			),
		);

		register_graphql_mutation(
			'login',
			array(
				'inputFields'         => array(
					'username' => array( 'type' => array( 'non_null' => 'String' ) ),
					'password' => array( 'type' => array( 'non_null' => 'String' ) ),
				),
				'outputFields'        => $output_fields,
				'mutateAndGetPayload' => static function ( $input ) {
					$service = AuthTokenService::create();
					$user_id = $service->authenticate_credentials(
						sanitize_user( (string) ( $input['username'] ?? '' ) ),
						(string) ( $input['password'] ?? '' )
					);

					if ( is_wp_error( $user_id ) ) {
						throw new \GraphQL\Error\UserError( esc_html( $user_id->get_error_message() ) );
					}

					return $service->issue_for( (int) $user_id );
				},
			)
		);

		register_graphql_mutation(
			'register',
			array(
				'inputFields'         => array(
					'username' => array( 'type' => array( 'non_null' => 'String' ) ),
					'email'    => array( 'type' => array( 'non_null' => 'String' ) ),
					'password' => array( 'type' => array( 'non_null' => 'String' ) ),
				),
				'outputFields'        => $output_fields,
				'mutateAndGetPayload' => static function ( $input ) {
					$username = sanitize_user( (string) ( $input['username'] ?? '' ) );
					$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
					$password = (string) ( $input['password'] ?? '' );

					if ( '' === $username || ! validate_username( $username ) || ! is_email( $email ) || strlen( $password ) < 8 ) {
						throw new \GraphQL\Error\UserError( esc_html__( 'Invalid registration details.', 'signalboard' ) );
					}

					if ( username_exists( $username ) || email_exists( $email ) ) {
						throw new \GraphQL\Error\UserError( esc_html__( 'That username or email is already registered.', 'signalboard' ) );
					}

					$user_id = wp_insert_user(
						array(
							'user_login' => $username,
							'user_email' => $email,
							'user_pass'  => $password,
							'role'       => 'subscriber',
						)
					);

					if ( is_wp_error( $user_id ) ) {
						throw new \GraphQL\Error\UserError( esc_html( $user_id->get_error_message() ) );
					}

					return AuthTokenService::create()->issue_for( (int) $user_id );
				},
			)
		);

		register_graphql_mutation(
			'refreshToken',
			array(
				'inputFields'         => array(
					'refreshToken' => array( 'type' => array( 'non_null' => 'String' ) ),
				),
				'outputFields'        => $output_fields,
				'mutateAndGetPayload' => static function ( $input ) {
					$result = AuthTokenService::create()->refresh( (string) ( $input['refreshToken'] ?? '' ) );

					if ( is_wp_error( $result ) ) {
						throw new \GraphQL\Error\UserError( esc_html( $result->get_error_message() ) );
					}

					return $result;
				},
			)
		);
	}
}
