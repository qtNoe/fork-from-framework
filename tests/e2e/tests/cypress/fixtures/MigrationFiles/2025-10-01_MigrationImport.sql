CREATE TABLE `migration_import` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL
);

INSERT INTO `migration_import` (`id`, `name`) VALUES
(1, 'Test Entry 1'), (2, 'Test Entry 2');