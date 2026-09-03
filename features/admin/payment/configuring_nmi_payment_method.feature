@managing_nmi_payment_methods
Feature: Configuring an NMI payment method
    In order to charge cards through my NMI account
    As an Administrator
    I want to configure NMI as a payment method

    Background:
        Given the store operates on a single channel in "United States"
        And I am logged in as an administrator

    @ui
    Scenario: NMI is offered as a gateway
        When I browse payment methods
        Then NMI should be available as a gateway factory

    @ui
    Scenario: Adding an NMI payment method
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its tokenization key as "tok-public-0123"
        And I set its security key as "sec-private-4567"
        And I choose the "production" environment
        And I add it
        Then I should be notified that it has been successfully created
        And the payment method "Card" should appear in the registry
        When I want to modify the "Card" payment method
        Then its gateway configuration "Tokenization key" should be "tok-public-0123"
        And its gateway configuration "Security key" should be "sec-private-4567"
        And its gateway configuration "Environment" should be "production"

    @ui
    Scenario: Trying to add an NMI payment method without a tokenization key
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its security key as "sec-private-4567"
        And I choose the "production" environment
        And I try to add it
        Then I should be notified that the NMI tokenization key is required
        And the payment method with name "Card" should not be added

    @ui
    Scenario: Trying to add an NMI payment method without a security key
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its tokenization key as "tok-public-0123"
        And I choose the "production" environment
        And I try to add it
        Then I should be notified that the NMI security key is required
        And the payment method with name "Card" should not be added

    @ui
    Scenario: Trying to add an NMI payment method without choosing an environment
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its tokenization key as "tok-public-0123"
        And I set its security key as "sec-private-4567"
        And I try to add it
        Then I should be notified that the NMI environment is required
        And the payment method with name "Card" should not be added

    @ui
    Scenario: Charging immediately is the default
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its tokenization key as "tok-public-0123"
        And I set its security key as "sec-private-4567"
        And I choose the "production" environment
        And I add it
        Then I should be notified that it has been successfully created
        And this payment method should charge cards immediately

    @ui
    Scenario: Choosing to authorize first and capture later
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its tokenization key as "tok-public-0123"
        And I set its security key as "sec-private-4567"
        And I choose the "sandbox" environment
        And I enable authorize-then-capture
        And I add it
        Then I should be notified that it has been successfully created
        And this payment method should authorize first and capture later

    @ui
    Scenario: Credentials are unreadable at rest
        When I want to create a new payment method with "NMI" gateway factory
        And I name it "Card" in "English (United States)"
        And I specify its code as "nmi_card"
        And I set its tokenization key as "tok-public-0123"
        And I set its security key as "sec-private-4567"
        And I choose the "production" environment
        And I add it
        Then I should be notified that it has been successfully created
        And the security key "sec-private-4567" of the "Card" payment method should not be readable in the database
