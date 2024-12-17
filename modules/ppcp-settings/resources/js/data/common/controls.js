/**
 * Controls: Implement side effects, typically asynchronous operations.
 *
 * Controls use ACTION_TYPES keys as identifiers.
 * They are triggered by corresponding actions and handle external interactions.
 *
 * @file
 */

import { dispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

import {
	STORE_NAME,
	REST_PERSIST_PATH,
	REST_MANUAL_CONNECTION_PATH,
	REST_CONNECTION_URL_PATH,
	REST_HYDRATE_MERCHANT_PATH,
	REST_REFRESH_FEATURES_PATH,
} from './constants';
import ACTION_TYPES from './action-types';

export const controls = {
	async [ ACTION_TYPES.DO_PERSIST_DATA ]( { data } ) {
		try {
			return await apiFetch( {
				path: REST_PERSIST_PATH,
				method: 'POST',
				data,
			} );
		} catch ( error ) {
			console.error( 'Error saving data.', error );
		}
	},

	async [ ACTION_TYPES.DO_SANDBOX_LOGIN ]() {
		let result = null;

		try {
			result = await apiFetch( {
				path: REST_CONNECTION_URL_PATH,
				method: 'POST',
				data: {
					environment: 'sandbox',
					products: [ 'EXPRESS_CHECKOUT' ], // Sandbox always uses EXPRESS_CHECKOUT.
				},
			} );
		} catch ( e ) {
			result = {
				success: false,
				error: e,
			};
		}

		return result;
	},

	async [ ACTION_TYPES.DO_PRODUCTION_LOGIN ]( { products } ) {
		let result = null;

		try {
			result = await apiFetch( {
				path: REST_CONNECTION_URL_PATH,
				method: 'POST',
				data: {
					environment: 'production',
					products,
				},
			} );
		} catch ( e ) {
			result = {
				success: false,
				error: e,
			};
		}

		return result;
	},

	async [ ACTION_TYPES.DO_MANUAL_CONNECTION ]( {
		clientId,
		clientSecret,
		useSandbox,
	} ) {
		let result = null;

		try {
			result = await apiFetch( {
				path: REST_MANUAL_CONNECTION_PATH,
				method: 'POST',
				data: {
					clientId,
					clientSecret,
					useSandbox,
				},
			} );
		} catch ( e ) {
			result = {
				success: false,
				error: e,
			};
		}

		return result;
	},

	async [ ACTION_TYPES.DO_REFRESH_MERCHANT ]() {
		let result = null;

		try {
			result = await apiFetch( { path: REST_HYDRATE_MERCHANT_PATH } );

			if ( result.success && result.merchant ) {
				await dispatch( STORE_NAME ).hydrate( result );
			}
		} catch ( e ) {
			result = {
				success: false,
				error: e,
			};
		}

		return result;
	},

	async [ ACTION_TYPES.DO_REFRESH_FEATURES ]() {
		let result = null;

		try {
			result = await apiFetch( {
				path: REST_REFRESH_FEATURES_PATH,
				method: 'POST',
			} );

			if ( result.success ) {
				result = await dispatch( STORE_NAME ).refreshMerchantData();
			}
		} catch ( e ) {
			result = {
				success: false,
				error: e,
				message: e.message,
			};
		}

		return result;
	},
};
