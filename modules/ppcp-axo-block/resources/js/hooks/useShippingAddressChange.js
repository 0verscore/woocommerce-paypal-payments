import { useCallback } from '@wordpress/element';
import { useAddressEditing } from './useAddressEditing';

export const useShippingAddressChange = (
	fastlaneSdk,
	setShippingAddress,
	setWooShippingAddress
) => {
	const { setShippingAddressEditing } = useAddressEditing();

	return useCallback( async () => {
		if ( fastlaneSdk ) {
			const { selectionChanged, selectedAddress } =
				await fastlaneSdk.profile.showShippingAddressSelector();
			if ( selectionChanged ) {
				setShippingAddress( selectedAddress );
				console.log(
					'Selected shipping address changed:',
					selectedAddress
				);

				const { address, name, phoneNumber } = selectedAddress;

				const newShippingAddress = {
					first_name: name.firstName,
					last_name: name.lastName,
					address_1: address.addressLine1,
					address_2: address.addressLine2 || '',
					city: address.adminArea2,
					state: address.adminArea1,
					postcode: address.postalCode,
					country: address.countryCode,
					phone: phoneNumber.nationalNumber,
				};

				await new Promise( ( resolve ) => {
					setWooShippingAddress( newShippingAddress );
					resolve();
				} );

				await new Promise( ( resolve ) => {
					setShippingAddressEditing( false );
					resolve();
				} );
			}
		}
	}, [
		fastlaneSdk,
		setShippingAddress,
		setWooShippingAddress,
		setShippingAddressEditing,
	] );
};

export default useShippingAddressChange;
