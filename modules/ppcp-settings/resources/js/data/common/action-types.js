/**
 * Action Types: Define unique identifiers for actions across all store modules.
 *
 * @file
 */

export default {
	// Transient data.
	SET_TRANSIENT: 'COMMON:SET_TRANSIENT',

	// Persistent data.
	SET_PERSISTENT: 'COMMON:SET_PERSISTENT',
	RESET: 'COMMON:RESET',
	HYDRATE: 'COMMON:HYDRATE',

	// Activity management (advanced solution that replaces the isBusy state).
	START_ACTIVITY: 'COMMON:START_ACTIVITY',
	STOP_ACTIVITY: 'COMMON:STOP_ACTIVITY',

	// Controls - always start with "DO_".
	DO_PERSIST_DATA: 'COMMON:DO_PERSIST_DATA',
	DO_MANUAL_CONNECTION: 'COMMON:DO_MANUAL_CONNECTION',
	DO_SANDBOX_LOGIN: 'COMMON:DO_SANDBOX_LOGIN',
	DO_PRODUCTION_LOGIN: 'COMMON:DO_PRODUCTION_LOGIN',
	DO_REFRESH_MERCHANT: 'COMMON:DO_REFRESH_MERCHANT',
	DO_REFRESH_FEATURES: 'DO_REFRESH_FEATURES',
};
