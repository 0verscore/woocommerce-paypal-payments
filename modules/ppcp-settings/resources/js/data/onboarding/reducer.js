import ACTION_TYPES from './action-types';

const defaultState = {
	isReady: false,
	isSaving: false,
	data: {
		completed: false,
		step: 0,
		useSandbox: false,
		useManualConnection: false,
		clientId: '',
		clientSecret: '',
		canUseCasualSelling: false,
		canUseVaulting: false,
		canUseCardPayments: false,
	},
};

export const onboardingReducer = (
	state = defaultState,
	{ type, ...action }
) => {
	const setTransient = ( changes ) => {
		const { data, ...transientChanges } = changes;
		return { ...state, ...transientChanges };
	};

	const setPersistent = ( changes ) => {
		const validChanges = Object.keys( changes ).reduce( ( acc, key ) => {
			if ( key in defaultState.data ) {
				acc[ key ] = changes[ key ];
			}
			return acc;
		}, {} );

		return {
			...state,
			data: { ...state.data, ...validChanges },
		};
	};

	switch ( type ) {
		// Transient data.
		case ACTION_TYPES.SET_ONBOARDING_IS_READY:
			return setTransient( { isReady: action.isReady } );

		case ACTION_TYPES.SET_IS_SAVING_ONBOARDING:
			return setTransient( { isSaving: action.isSaving } );

		// Persistent data.
		case ACTION_TYPES.SET_ONBOARDING_DETAILS:
			return setPersistent( action.payload );

		case ACTION_TYPES.SET_ONBOARDING_COMPLETED:
			return setPersistent( { completed: action.completed } );

		case ACTION_TYPES.SET_CLIENT_ID:
			return setPersistent( { clientId: action.clientId } );

		case ACTION_TYPES.SET_CLIENT_SECRET:
			return setPersistent( { clientSecret: action.clientSecret } );

		case ACTION_TYPES.SET_ONBOARDING_STEP:
			return setPersistent( { step: action.step } );

		case ACTION_TYPES.SET_SANDBOX_MODE:
			return setPersistent( { useSandbox: action.useSandbox } );

		case ACTION_TYPES.SET_MANUAL_CONNECTION_MODE:
			return setPersistent( {
				useManualConnection: action.useManualConnection,
			} );

		default:
			return state;
	}
};

export default onboardingReducer;