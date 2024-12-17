import { Button } from '@wordpress/components';
import SettingsBlock from './SettingsBlock';
import { Header, Title, Action, Description } from './SettingsBlockElements';

const ButtonSettingsBlock = ( { title, description, ...props } ) => (
	<SettingsBlock { ...props } className="ppcp-r-settings-block__button">
		<Header>
			<Title>{ title }</Title>
			<Description>{ description }</Description>
		</Header>
		<Action>
			<Button
				variant={ props.actionProps?.buttonType }
				onClick={
					props.actionProps?.callback
						? () => props.actionProps.callback()
						: undefined
				}
			>
				{ props.actionProps.value }
			</Button>
		</Action>
	</SettingsBlock>
);

export default ButtonSettingsBlock;
